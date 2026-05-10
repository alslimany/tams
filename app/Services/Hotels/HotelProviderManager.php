<?php

namespace App\Services\Hotels;

use App\Contracts\Hotels\HotelProviderInterface;
use App\Models\Tenant\TenantHotelProvider;

class HotelProviderManager
{
    public function activeProvider(): ?TenantHotelProvider
    {
        return TenantHotelProvider::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }

    public function provider(): HotelProviderInterface
    {
        $provider = $this->activeProvider();

        if (! $provider instanceof TenantHotelProvider) {
            throw new HotelApiException('Hotel provider is not configured.');
        }

        return HotelProviderFactory::make($provider);
    }
}
