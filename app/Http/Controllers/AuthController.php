<?php

namespace App\Http\Controllers;

use App\Models\ActivationCode;
use App\Models\Device;
use App\Services\PinLoginLockout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function activate(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'device_identifier' => 'required|string|min:14',
            'device_name' => 'required|string',
        ]);

        $submittedCode = trim($request->input('code'));

        $activeCodes = ActivationCode::whereNull('used_at')
            ->where('expires_at', '>', now())
            ->get();

        $matchedCode = null;

        foreach ($activeCodes as $activationCode) {
            $isMatch = Hash::check($submittedCode, $activationCode->code);

            if ($isMatch) {
                $matchedCode = $activationCode;
                break;
            }
        }

        if (! $matchedCode) {
            return response()->json([
                'message' => 'Aktivacioni kod je nevalidan ili istekao.',
            ], 422);
        }

        $user = $matchedCode->user;

        $matchedCode->used_at = now();
        $matchedCode->save();

        $device = Device::updateOrCreate(
            ['device_identifier' => $request->device_identifier],
            [
                'user_id' => $user->id,
                'device_name' => $request->device_name ?? 'Nepoznat uređaj',
                'device_identifier' => $request->device_identifier,
                'is_trusted' => true,
                'last_login_at' => now(),
            ]
        );

        if (is_null($user->pin_hash)) {
            $user->status = 'pending_pin';
            $user->save();
        }

        return response()->json([
            'message' => 'Kod je uspešno verifikovan. Uredjaj registrovan',
            // 'user_id' => $user->id,
            'user_status' => $user->status,
        ]);
    }

    public function setupPin(Request $request)
    {
        $request->validate([
            'device_identifier' => 'required|string',
            'pin' => 'required|digits:4',
        ]);

        $device = Device::where('device_identifier', $request->device_identifier)->first();

        if (! $device || ! $device->is_trusted) {
            return response()->json([
                'status' => 'error',
                'message' => 'Uređaj nije pronađen ili je blokiran.',
            ], 403);
        }

        $user = $device->user;

        if (! is_null($user->pin_hash)) {
            return response()->json([
                'status' => 'error',
                'message' => 'PIN je već postavljen za ovaj nalog.',
            ], 400);
        }

        $user->update([
            'pin_hash' => Hash::make($request->pin),
            'status' => 'active',
        ]);

        $token = $user->createToken($device->device_identifier)->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'PIN je uspešno postavljen.',
            'token' => $token,
            // 'user'    => [
            //     'id'         => $user->id,
            //     'first_name' => $user->first_name,
            //     'last_name'  => $user->last_name,
            // ]
        ], 200);
    }

    public function login(Request $request, PinLoginLockout $lockout)
    {
        $request->validate([
            'device_identifier' => 'required|string',
            'pin' => 'required|digits:4',
        ]);

        $deviceIdentifier = $request->string('device_identifier')->toString();

        if ($lockout->isLocked($deviceIdentifier)) {
            return $this->pinLockoutResponse($lockout, $deviceIdentifier);
        }

        $device = Device::where('device_identifier', $deviceIdentifier)->first();

        if (! $device || ! $device->is_trusted) {
            $lockout->recordFailure($deviceIdentifier);

            if ($lockout->isLocked($deviceIdentifier)) {
                return $this->pinLockoutResponse($lockout, $deviceIdentifier);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Uređaj nije pronađen ili nije autorizovan.',
            ], Response::HTTP_FORBIDDEN);
        }

        $user = $device->user;

        if ($user->status !== 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'Nalog nije aktivan.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (is_null($user->pin_hash)) {
            return response()->json([
                'status' => 'error',
                'message' => 'PIN nije postavljen. Molimo vas da prvo završite aktivaciju naloga.',
            ], 400);
        }

        if (! Hash::check($request->pin, $user->pin_hash)) {
            $lockout->recordFailure($deviceIdentifier);

            if ($lockout->isLocked($deviceIdentifier)) {
                return $this->pinLockoutResponse($lockout, $deviceIdentifier);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Pogrešan PIN kod.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $lockout->clear($deviceIdentifier);

        $device->update(['last_login_at' => now()]);

        $token = $user->createToken($device->device_identifier)->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Uspešna prijava.',
            'token' => $token,
            // 'user'    => [
            //     'id'         => $user->id,
            //     'first_name' => $user->first_name,
            //     'last_name'  => $user->last_name,
            // ]
        ], 200);
    }

    private function pinLockoutResponse(PinLoginLockout $lockout, string $deviceIdentifier): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Previše neuspešnih pokušaja. Pokušajte ponovo kasnije.',
            'retry_after' => $lockout->retryAfter($deviceIdentifier),
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function confirmPin(Request $request)
    {
        $validated = $request->validate([
            'pin' => 'required|digits:4',
        ]);

        if (! Hash::check($validated['pin'], $request->user()->pin_hash)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pogrešan PIN kod.',
            ], 401);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Identitet je potvrđen.',
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();

        if ($token) {
            $request->user()->tokens()->whereKey($token->id)->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Uspešno ste izlogovani.',
        ], 200);
    }
}
