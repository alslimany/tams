<?php

namespace App\Http\Requests\Tenant\ESim;

use Illuminate\Foundation\Http\FormRequest;

class ESimSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country' => ['required', 'string', 'max:100'],
            'data_mb' => ['nullable', 'integer', 'min:1'],
            'validity_days' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
