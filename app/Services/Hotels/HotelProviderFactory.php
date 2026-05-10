<?php

namespace App\Services\Hotels;

use App\Contracts\Hotels\HotelProviderInterface;
use App\Models\Tenant\TenantHotelProvider;
use App\Services\Hotels\Providers\ThreeTProvider;

class HotelProviderFactory
{
    public static function make(TenantHotelProvider $config): HotelProviderInterface
    {
        $providerType = strtolower(trim((string) $config->provider_type));

        return match ($providerType) {
            '3t', 'threet', 'three_t' => new ThreeTProvider($config),
            default => throw new HotelApiException("Unsupported hotel provider type: {$providerType}"),
        };
    }
}
