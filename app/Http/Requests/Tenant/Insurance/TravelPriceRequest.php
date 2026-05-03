<?php

namespace App\Http\Requests\Tenant\Insurance;

use Illuminate\Foundation\Http\FormRequest;

class TravelPriceRequest extends FormRequest
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
            'zone_id' => ['required', 'integer', 'min:1'],
            'policy_date_from' => ['required', 'date'],
            'policy_date_to' => ['required', 'date', 'after_or_equal:policy_date_from'],
            'adult_count' => ['nullable', 'integer', 'min:0', 'max:30'],
            'child_count' => ['nullable', 'integer', 'min:0', 'max:30'],
            'senior_count' => ['nullable', 'integer', 'min:0', 'max:30'],
            'passengers' => ['nullable', 'array', 'min:1', 'max:30'],
            'passengers.*.first_name' => ['nullable', 'string', 'max:100'],
            'passengers.*.last_name' => ['nullable', 'string', 'max:100'],
            'passengers.*.birth_date' => ['required_with:passengers', 'date', 'before_or_equal:today'],
            'passengers.*.gender_id' => ['nullable', 'integer', 'in:1,2'],
            'passengers.*.birth_place' => ['nullable', 'string', 'max:150'],
            'passengers.*.passport_number' => ['nullable', 'string', 'max:50'],
            'passengers.*.nationality_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
