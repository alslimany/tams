<?php

namespace App\Http\Requests\Tenant\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class HotelBookRequest extends FormRequest
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
            'recommandations' => ['nullable', 'string', 'max:1000'],
            'customer.first_name' => ['required', 'string', 'max:100'],
            'customer.last_name' => ['required', 'string', 'max:100'],
            'customer.email' => ['required', 'email', 'max:255'],
            'customer.mobile' => ['required', 'string', 'max:50'],
            'customer.country' => ['required', 'string', 'max:100'],
            'customer.city' => ['required', 'string', 'max:100'],
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.rate_key' => ['required', 'string'],
            'rooms.*.paxes' => ['required', 'array', 'min:1'],
            'rooms.*.paxes.*.civility' => ['required', 'in:Mr,Mme,Mlle,Enf'],
            'rooms.*.paxes.*.first_name' => ['required', 'string', 'max:100'],
            'rooms.*.paxes.*.last_name' => ['required', 'string', 'max:100'],
            'rooms.*.paxes.*.age' => ['nullable', 'integer', 'min:0', 'max:17'],
        ];
    }
}
