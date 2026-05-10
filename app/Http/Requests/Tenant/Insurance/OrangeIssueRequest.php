<?php

namespace App\Http\Requests\Tenant\Insurance;

use Illuminate\Foundation\Http\FormRequest;

class OrangeIssueRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'chassis_number' => ['required', 'string', 'max:100'],
            'metal_plate_number' => ['required', 'string', 'max:100'],
            'manufacture_year' => ['required', 'integer', 'min:1900', 'max:'.((int) now()->addYear()->year)],
            'car_id' => ['required', 'integer', 'min:1'],
            'nationality' => ['required', 'integer', 'min:1'],
        ];
    }
}
