<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeletePasskeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirmation_token' => ['required', 'string', 'size:64'],
        ];
    }
}
