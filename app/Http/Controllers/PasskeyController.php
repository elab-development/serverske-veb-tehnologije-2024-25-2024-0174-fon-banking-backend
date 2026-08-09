<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeletePasskeyRequest;
use App\Http\Requests\PasskeyRegistrationOptionsRequest;
use App\Http\Requests\StorePasskeyRequest;
use App\Services\PasskeyCeremonyStore;
use App\Services\PinConfirmationGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Webauthn\PublicKeyCredentialCreationOptions;

class PasskeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $passkeys = $request->user()->passkeys()
            ->latest()
            ->get(['id', 'name', 'last_used_at', 'created_at'])
            ->map(fn (Passkey $passkey): array => [
                'id' => $passkey->getKey(),
                'name' => $passkey->name,
                'last_used_at' => $passkey->last_used_at,
                'created_at' => $passkey->created_at,
            ]);

        return response()->json(['data' => $passkeys]);
    }

    public function registrationOptions(
        PasskeyRegistrationOptionsRequest $request,
        PinConfirmationGrant $grants,
        GenerateRegistrationOptions $generateOptions,
        PasskeyCeremonyStore $ceremonies,
    ): JsonResponse {
        $accessToken = $this->accessToken($request);
        $user = $request->user();

        if (! $grants->consume(
            $request->validated('confirmation_token'),
            $user->getKey(),
            $accessToken->getKey(),
            'passkeys.manage',
        )) {
            $this->invalidConfirmation();
        }

        $options = $generateOptions($user);
        $ceremonyId = $ceremonies->store(
            'registration',
            WebAuthn::toJson($options),
            $user->getKey(),
            accessTokenId: $accessToken->getKey(),
        );

        return response()->json([
            'ceremony_id' => $ceremonyId,
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    public function store(
        StorePasskeyRequest $request,
        PasskeyCeremonyStore $ceremonies,
        StorePasskey $storePasskey,
    ): JsonResponse {
        $accessToken = $this->accessToken($request);
        $user = $request->user();
        $payload = $ceremonies->consume($request->validated('ceremony_id'), 'registration');

        if ($payload === null
            || $payload['user_id'] !== $user->getKey()
            || $payload['access_token_id'] !== $accessToken->getKey()) {
            throw ValidationException::withMessages([
                'ceremony_id' => ['Ceremonija je nevalidna ili istekla.'],
            ]);
        }

        try {
            $options = WebAuthn::fromJson($payload['options'], PublicKeyCredentialCreationOptions::class);
            $passkey = $storePasskey(
                $user,
                $request->validated('name'),
                $request->credential(),
                $options,
            );
        } catch (Throwable $exception) {
            Log::warning('Passkey registration failed.', [
                'user_id' => $user->getKey(),
                'exception' => $exception,
            ]);

            throw ValidationException::withMessages([
                'credential' => ['Registracija passkeya nije uspela. Zatražite nove options.'],
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $passkey->getKey(),
                'name' => $passkey->name,
                'last_used_at' => $passkey->last_used_at,
                'created_at' => $passkey->created_at,
            ],
        ], Response::HTTP_CREATED);
    }

    public function destroy(
        DeletePasskeyRequest $request,
        Passkey $passkey,
        PinConfirmationGrant $grants,
        DeletePasskey $deletePasskey,
    ): Response {
        $accessToken = $this->accessToken($request);
        $user = $request->user();

        if (! $grants->consume(
            $request->validated('confirmation_token'),
            $user->getKey(),
            $accessToken->getKey(),
            'passkeys.manage',
        )) {
            $this->invalidConfirmation();
        }

        $ownedPasskey = $user->passkeys()->findOrFail($passkey->getKey());
        $deletePasskey($user, $ownedPasskey);

        return response()->noContent();
    }

    private function accessToken(Request $request): PersonalAccessToken
    {
        $accessToken = $request->user()->currentAccessToken();

        if (! $accessToken instanceof PersonalAccessToken) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return $accessToken;
    }

    private function invalidConfirmation(): never
    {
        throw ValidationException::withMessages([
            'confirmation_token' => ['PIN potvrda je nevalidna ili istekla.'],
        ]);
    }
}
