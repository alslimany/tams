<?php

namespace App\Http\Requests\Tenant\Insurance;

use Illuminate\Foundation\Http\FormRequest;

class CompulsoryPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, \Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'document_type_id' => ['required', 'integer', 'min:1'],
            'duration_id' => ['required', 'integer', 'min:1'],
            'seats' => ['required', 'integer', 'min:1', 'max:200'],
            'payload' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
