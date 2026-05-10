<?php

namespace App\Http\Requests\Tenant\Insurance;

use Illuminate\Foundation\Http\FormRequest;

class OrangePriceRequest extends FormRequest
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
            'country' => ['required', 'integer', 'min:1'],
            'document_type_id' => ['required', 'integer', 'min:1'],
            'policy_date_from' => ['required', 'date'],
            'policy_date_to' => ['required', 'date', 'after:policy_date_from'],
        ];
    }
}
