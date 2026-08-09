<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;

abstract class PasskeyCredentialRequest extends FormRequest
{
    private PublicKeyCredential $publicKeyCredential;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function credentialRules(): array
    {
        return [
            'ceremony_id' => ['required', 'string', 'size:64'],
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string', 'max:2048'],
            'credential.rawId' => ['required', 'string', 'max:2048'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ];
    }

    protected function passedValidation(): void
    {
        try {
            $this->publicKeyCredential = WebAuthn::fromJson(
                json_encode($this->input('credential'), JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
            );
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'credential' => ['Neispravan format passkey credentiala.'],
            ]);
        }
    }

    public function credential(): PublicKeyCredential
    {
        return $this->publicKeyCredential;
    }
}
