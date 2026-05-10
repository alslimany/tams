<?php

namespace App\Http\Requests\Tenant\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class HotelSelectRequest extends FormRequest
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
            'search_uuid' => ['required', 'uuid'],
            'hotel_id' => ['required', 'string', 'max:120'],
            'hotel_uid' => ['nullable', 'string', 'max:120'],
            'hotel_name' => ['required', 'string', 'max:255'],
            'source' => ['required'],
            'rate_key' => ['required', 'string', 'max:4000'],
            'rate_keys' => ['nullable', 'array'],
            'rate_keys.*' => ['string', 'max:4000'],
            'room_name' => ['nullable', 'string', 'max:255'],
            'board_name' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'available' => ['nullable', 'boolean'],
            'cancellation_policies' => ['nullable', 'array'],
            'raw' => ['nullable', 'array'],
        ];
    }
}
