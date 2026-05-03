<?php

namespace App\Http\Requests\Tenant\Insurance;

use Illuminate\Foundation\Http\FormRequest;

class TravelIssueRequest extends FormRequest
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
            'quote_token' => ['required', 'string', 'max:100'],
            'client_name' => ['required', 'string', 'max:150'],
            'client_phone' => ['required', 'string', 'max:30'],
            'client_address' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'passengers' => ['required', 'array', 'min:1', 'max:30'],
            'passengers.*.first_name' => ['required', 'string', 'max:100'],
            'passengers.*.last_name' => ['required', 'string', 'max:100'],
            'passengers.*.birth_date' => ['required', 'date', 'before_or_equal:today'],
            'passengers.*.gender_id' => ['required', 'integer', 'in:1,2'],
            'passengers.*.birth_place' => ['required', 'string', 'max:150'],
            'passengers.*.passport_number' => ['required', 'string', 'max:50'],
            'passengers.*.nationality_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
