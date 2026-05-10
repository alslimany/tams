<?php

namespace App\Http\Requests\Tenant\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class HotelSearchRequest extends FormRequest
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
            'city' => ['required', 'string', 'max:120'],
            'city_id' => ['nullable', 'integer', 'min:1'],
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'rooms' => ['required', 'array', 'min:1', 'max:6'],
            'rooms.*.adult' => ['required', 'integer', 'min:1', 'max:9'],
            'rooms.*.children' => ['nullable', 'array', 'max:6'],
            'rooms.*.children.*' => ['integer', 'min:0', 'max:17'],
        ];
    }
}
