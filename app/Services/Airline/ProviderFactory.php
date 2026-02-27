<?php

namespace App\Services\Airline;

use App\Models\TenantProvider;
use App\Services\Airline\Videcom\Airlines\OyaAirline;
use App\Services\Airline\Videcom\Airlines\MedskyAirline;
use Exception;

class ProviderFactory
{
    /**
     * Create a provider instance based on tenant configuration.
     *
     * @param TenantProvider $config
     * @param array $context Optional context (e.g. ['origin' => 'MLA', 'currency' => 'EUR'])
     * @return AirlineProviderInterface
     * @throws Exception
     */
    public static function make(TenantProvider $config, array $context = []): AirlineProviderInterface
    {
        return match ($config->provider_type) {
            'videcom' => self::makeVidecomProvider($config, $context),
            // 'amadeus' => new AmadeusProvider($config->credentials),
            default => throw new Exception("Unsupported provider type: {$config->provider_type}"),
        };
    }

    /**
     * Resolve specific Videcom airline handler.
     */
    protected static function makeVidecomProvider(TenantProvider $config, array $context): AirlineProviderInterface
    {
        $credentials = $config->credentials;
        
        // Merge context into credentials if needed (e.g. selecting specific account from multi-account config)
        // For now, we assume each TenantProvider record represents ONE account.
        
        return match ($config->airline_code) {
            'YI', 'OYa' => new OyaAirline($credentials),
            'BM', 'Medsky' => new MedskyAirline($credentials),
            default => throw new Exception("Unsupported Videcom airline code: {$config->airline_code}"),
        };
    }
}
