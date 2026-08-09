<?php

namespace App\Http\Requests;

class PasskeyLoginRequest extends PasskeyCredentialRequest
{
    public function rules(): array
    {
        return array_merge($this->credentialRules(), [
            'device_identifier' => ['required', 'string', 'min:14', 'max:255'],
        ]);
    }
}
