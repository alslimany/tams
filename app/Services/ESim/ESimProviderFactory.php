<?php

namespace App\Services\ESim;

use App\Contracts\ESim\ESimProviderInterface;
use App\Models\Tenant\TenantEsimProvider;
use App\Services\ESim\Providers\L2Provider;

class ESimProviderFactory
{
    public static function make(TenantEsimProvider $config): ESimProviderInterface
    {
        $providerType = strtolower(trim((string) $config->provider_type));

        return match ($providerType) {
            'l2', 'l2travel' => new L2Provider($config),
            default => throw new ESimApiException("Unsupported eSIM provider type: {$providerType}"),
        };
    }
}
