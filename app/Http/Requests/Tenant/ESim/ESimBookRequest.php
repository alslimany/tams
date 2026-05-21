<?php

namespace App\Http\Requests\Tenant\ESim;

use Illuminate\Foundation\Http\FormRequest;

class ESimBookRequest extends FormRequest
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
            'booking_uuid' => ['required', 'uuid'],
            'customer.name' => ['required', 'string', 'max:200'],
            'customer.email' => ['required', 'email', 'max:255'],
        ];
    }
}
