<?php

namespace App\Http\Controllers;

use App\Services\AccountNumberService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class NbsQrController extends Controller
{
    public function validateQr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ]);

        return $this->sendToNbs('validate', $validated['text']);
    }

    public function generate(Request $request, AccountNumberService $accountNumbers): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $tags = collect(explode('|', $validated['text']))
            ->mapWithKeys(function (string $part): array {
                [$key, $value] = array_pad(explode(':', $part, 2), 2, '');

                return [$key => $value];
            });

        if (
            $tags->get('K') !== 'PR'
            || ! str_starts_with((string) $tags->get('I'), 'RSD')
            || ! $accountNumbers->isQrEligible((string) $tags->get('R'), 'RSD')
        ) {
            return response()->json([
                'message' => 'QR kod može da se generiše samo za validan FON Banka RSD račun.',
            ], 422);
        }

        return $this->sendToNbs('generate/400', $validated['text']);
    }

    private function sendToNbs(string $method, string $text): JsonResponse
    {
        $baseUrl = rtrim((string) config('services.nbs_qr.url'), '/');

        try {
            $response = Http::acceptJson()
                ->withBody($text, 'text/plain; charset=UTF-8')
                ->timeout(15)
                ->post("{$baseUrl}/{$method}?lang=sr_RS_Latn");
        } catch (ConnectionException) {
            return response()->json([
                'message' => 'NBS IPS QR servis trenutno nije dostupan.',
            ], 502);
        }

        $data = $response->json();

        if ($response->failed() || ! is_array($data)) {
            return response()->json([
                'message' => 'NBS IPS QR servis je vratio neispravan odgovor.',
            ], 502);
        }

        return response()->json($data);
    }
}
