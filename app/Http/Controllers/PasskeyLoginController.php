<?php

namespace App\Http\Controllers;

use App\Http\Requests\PasskeyLoginOptionsRequest;
use App\Http\Requests\PasskeyLoginRequest;
use App\Models\Device;
use App\Services\DeviceTokenIssuer;
use App\Services\PasskeyCeremonyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredentialRequestOptions;

class PasskeyLoginController extends Controller
{
    public function options(
        PasskeyLoginOptionsRequest $request,
        GenerateVerificationOptions $generateOptions,
        PasskeyCeremonyStore $ceremonies,
    ): JsonResponse {
        $device = Device::query()
            ->with('user')
            ->where('device_identifier', $request->validated('device_identifier'))
            ->first();

        if ($device === null
            || ! $device->is_trusted
            || $device->user->status !== 'active'
            || ! $device->user->hasPasskeysEnabled()) {
            $this->invalidLogin();
        }

        $options = $generateOptions($device->user);
        $ceremonyId = $ceremonies->store(
            'login',
            WebAuthn::toJson($options),
            $device->user_id,
            $device->id,
        );

        return response()->json([
            'ceremony_id' => $ceremonyId,
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    public function store(
        PasskeyLoginRequest $request,
        PasskeyCeremonyStore $ceremonies,
        VerifyPasskey $verifyPasskey,
        DeviceTokenIssuer $tokens,
    ): JsonResponse {
        $payload = $ceremonies->consume($request->validated('ceremony_id'), 'login');

        if ($payload === null) {
            $this->invalidLogin();
        }

        $device = Device::query()->with('user')->find($payload['device_id']);

        if ($device === null
            || $device->device_identifier !== $request->validated('device_identifier')
            || $device->user_id !== $payload['user_id']
            || ! $device->is_trusted
            || $device->user->status !== 'active') {
            $this->invalidLogin();
        }

        try {
            $options = WebAuthn::fromJson($payload['options'], PublicKeyCredentialRequestOptions::class);
            $passkey = $verifyPasskey($request->credential(), $options, $device->user);
        } catch (Throwable) {
            $this->invalidLogin();
        }

        if (! Passkeys::allowsLogin($request, $passkey)) {
            $this->invalidLogin();
        }

        $device->update(['last_login_at' => now()]);
        $token = $tokens->issue($device)->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Uspešna prijava.',
            'token' => $token,
        ]);
    }

    private function invalidLogin(): never
    {
        throw ValidationException::withMessages([
            'credential' => ['Passkey prijava nije uspela. Zatražite nove options i pokušajte ponovo.'],
        ]);
    }
}
