<?php

namespace App\Http\Controllers;

use App\Models\ActivationCode;
use App\Models\Device;
use App\Models\PinEnrollmentToken;
use App\Services\DeviceTokenIssuer;
use App\Services\PinConfirmationGrant;
use App\Services\PinLoginLockout;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'email' => $user->email,
            'phoneNumber' => $user->phone_number,
        ]);
    }

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

        $result = DB::transaction(function () use ($matchedCode, $request): array {
            $activationCode = ActivationCode::query()->lockForUpdate()->findOrFail($matchedCode->id);

            if (! $activationCode->isValid()) {
                throw ValidationException::withMessages([
                    'code' => ['Aktivacioni kod je nevalidan ili istekao.'],
                ]);
            }

            $user = $activationCode->user()->lockForUpdate()->firstOrFail();

            if (in_array($user->status, ['blocked', 'system'], true) || $user->pin_hash !== null) {
                throw ValidationException::withMessages([
                    'code' => ['Aktivacioni kod je nevalidan ili istekao.'],
                ]);
            }

            $device = Device::query()->where('device_identifier', $request->device_identifier)->lockForUpdate()->first();

            if ($device !== null && $device->user_id !== $user->id) {
                throw ValidationException::withMessages([
                    'device_identifier' => ['Uređaj nije dostupan za aktivaciju.'],
                ]);
            }

            $device ??= new Device([
                'user_id' => $user->id,
                'device_identifier' => $request->device_identifier,
            ]);
            $device->fill([
                'device_name' => $request->device_name,
                'is_trusted' => true,
                'last_login_at' => now(),
            ])->save();

            $activationCode->update(['used_at' => now()]);
            $user->update(['status' => 'pending_pin']);

            PinEnrollmentToken::query()
                ->where('user_id', $user->id)
                ->where('device_id', $device->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $plainToken = bin2hex(random_bytes(32));
            $expiresIn = (int) config('auth.pin_enrollment.ttl', 600);

            PinEnrollmentToken::create([
                'user_id' => $user->id,
                'device_id' => $device->id,
                'token_hash' => hash('sha256', $plainToken),
                'purpose' => 'pin.enroll',
                'expires_at' => now()->addSeconds($expiresIn),
            ]);

            return [$user, $plainToken, $expiresIn];
        });

        [$user, $enrollmentToken, $expiresIn] = $result;

        return response()->json([
            'message' => 'Kod je uspešno verifikovan. Uredjaj registrovan',
            'user_status' => $user->status,
            'enrollment_token' => $enrollmentToken,
            'expires_in' => $expiresIn,
        ]);
    }

    public function setupPin(Request $request, DeviceTokenIssuer $tokens): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_token' => ['required', 'string', 'size:64'],
            'pin' => 'required|digits:4',
        ]);

        try {
            $token = DB::transaction(function () use ($validated, $tokens): string {
                $enrollment = PinEnrollmentToken::query()
                    ->where('token_hash', hash('sha256', $validated['enrollment_token']))
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($enrollment->consumed_at !== null
                    || $enrollment->expires_at->isPast()
                    || $enrollment->purpose !== 'pin.enroll') {
                    throw new ModelNotFoundException;
                }

                $user = $enrollment->user()->lockForUpdate()->firstOrFail();
                $device = $enrollment->device()->lockForUpdate()->firstOrFail();

                if ($device->user_id !== $user->id
                    || ! $device->is_trusted
                    || $user->status !== 'pending_pin'
                    || $user->pin_hash !== null) {
                    throw new ModelNotFoundException;
                }

                $enrollment->update(['consumed_at' => now()]);
                $user->update([
                    'pin_hash' => $validated['pin'],
                    'status' => 'active',
                ]);

                return $tokens->issue($device)->plainTextToken;
            });
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages([
                'enrollment_token' => ['Enrollment token je nevalidan ili istekao.'],
            ]);
        }

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

    public function login(Request $request, PinLoginLockout $lockout, DeviceTokenIssuer $tokens)
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

        $token = $tokens->issue($device)->plainTextToken;

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

    public function confirmPin(Request $request, PinConfirmationGrant $grants): JsonResponse
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

        $accessToken = $request->user()->currentAccessToken();

        if (! $accessToken instanceof PersonalAccessToken) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $grant = $grants->issue(
            $request->user()->getKey(),
            $accessToken->getKey(),
            'passkeys.manage',
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Identitet je potvrđen.',
            'confirmation_token' => $grant['token'],
            'expires_in' => $grant['expires_in'],
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
