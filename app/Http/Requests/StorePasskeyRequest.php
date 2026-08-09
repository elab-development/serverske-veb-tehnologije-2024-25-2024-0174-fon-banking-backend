<?php

namespace App\Http\Requests;

class StorePasskeyRequest extends PasskeyCredentialRequest
{
    public function rules(): array
    {
        return array_merge($this->credentialRules(), [
            'name' => ['required', 'string', 'max:255'],
        ]);
    }
}
