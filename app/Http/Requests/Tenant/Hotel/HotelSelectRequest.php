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
            'provider_source' => ['nullable', 'array'],
            'provider_source.source_type' => ['nullable', 'string'],
            'provider_source.provider_selector' => ['nullable', 'string'],
            'provider_source.source_agency_tenant_id' => ['nullable', 'string'],
            'provider_source.merchant_tenant_id' => ['nullable', 'string'],
            'provider_source.network_membership_id' => ['nullable'],
            'provider_source.provider_allocation_id' => ['nullable'],
            'provider_source.provider_type' => ['nullable', 'string'],
            'provider_source.provider_driver' => ['nullable', 'string'],
            'provider_source.provider_identity' => ['nullable', 'string'],
            'provider_source.source_provider_model' => ['nullable', 'string'],
            'provider_source.source_provider_id' => ['nullable'],
            'provider_source.commission_rate' => ['nullable'],
            'provider_source.markup_rate' => ['nullable'],
            'provider_source.financial_terms' => ['nullable'],
            'raw' => ['nullable', 'array'],
        ];
    }
}
