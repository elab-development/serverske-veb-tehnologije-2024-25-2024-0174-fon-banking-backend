<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasskeyLoginOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_identifier' => ['required', 'string', 'min:14', 'max:255'],
        ];
    }
}
