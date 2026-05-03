<?php

namespace App\Http\Requests\Tenant\Insurance;

use Illuminate\Foundation\Http\FormRequest;

class CompulsoryIssueRequest extends FormRequest
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
            'policy_date_from' => ['required', 'date'],
            'beneficiary_name' => ['required', 'string', 'max:150'],
            'beneficiary_phone' => ['required', 'string', 'max:30'],
            'beneficiary_address' => ['nullable', 'string', 'max:255'],
            'beneficiary_email' => ['nullable', 'email', 'max:255'],
            'vehicle_type_id' => ['required', 'integer', 'min:1'],
            'vehicle_color_id' => ['required', 'integer', 'min:1'],
            'vehicle_licensing_authority_id' => ['required', 'integer', 'min:1'],
            'vehicle_manufacture_year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'vehicle_chassis_number' => ['required', 'string', 'max:100'],
            'vehicle_plate_number' => ['required', 'string', 'max:60'],
            'vehicle_payload' => ['nullable', 'numeric', 'min:0'],
            'vehicle_type_engine_power' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
