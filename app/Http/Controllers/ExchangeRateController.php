<?php

namespace App\Http\Controllers;

use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    public function index(Request $request, ExchangeRateService $exchangeRates): JsonResponse
    {
        $validated = $request->validate([
            'base' => ['sometimes', 'string', 'size:3'],
        ]);
        $base = strtoupper($validated['base'] ?? 'RSD');

        abort_unless(isset(config('exchange_rates.currencies')[$base]), 422, 'Unsupported base currency.');

        return response()->json([
            'base' => $base,
            'spread' => (float) config('exchange_rates.spread'),
            'currencies' => collect(config('exchange_rates.currencies'))
                ->map(fn (array $currency, string $code): array => [
                    'code' => $code,
                    ...$currency,
                ])
                ->values(),
            'rates' => $exchangeRates->rates($base),
        ]);
    }
}
