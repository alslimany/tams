<?php

namespace App\Services\Airline;

use App\Models\TenantProvider;
use App\Services\Airline\Videcom\Airlines\BerniqAirline;
use App\Services\Airline\Videcom\Airlines\BuraqAirline;
use App\Services\Airline\Videcom\Airlines\CrownAirline;
use App\Services\Airline\Videcom\Airlines\GlobalAirline;
use App\Services\Airline\Videcom\Airlines\LibyaExpressAirline;
use App\Services\Airline\Videcom\Airlines\LibyanWingsAirline;
use App\Services\Airline\Videcom\Airlines\MedskyAirline;
use App\Services\Airline\Videcom\Airlines\OyaAirline;
use Exception;

class ProviderFactory
{
    /**
     * Create a provider instance based on tenant configuration.
     *
     * @param  array  $context  Optional context (e.g. ['origin' => 'MLA', 'currency' => 'EUR'])
     *
     * @throws Exception
     */
    public static function make(TenantProvider $config, array $context = []): AirlineProviderInterface
    {
        if ($config->provider_type === null) {
            throw new Exception('Missing provider type');
        }
        $providerType = strtolower(trim((string) $config->provider_type));

        return match ($providerType) {
            'videcom' => self::makeVidecomProvider($config, $context),
            // 'amadeus' => new AmadeusProvider($config->credentials),
            default => throw new Exception("Unsupported provider type: {$providerType}"),
        };
    }

    /**
     * Resolve specific Videcom airline handler.
     */
    protected static function makeVidecomProvider(TenantProvider $config, array $context): AirlineProviderInterface
    {
        $credentials = array_merge($config->credentials ?? [], [
            'tenant_provider_id' => $config->id,
            'account_name' => $config->account_name,
            'airline_code' => $config->airline_code,
        ]);

        // Merge context into credentials if needed (e.g. selecting specific account from multi-account config)
        // For now, we assume each TenantProvider record represents ONE account.

        return match ($config->airline_code) {
            'YI', 'OYa' => new OyaAirline($credentials),
            'BM', 'Medsky' => new MedskyAirline($credentials),
            'UZ', 'Buraq' => new BuraqAirline($credentials),
            'YL', 'LibyanWings' => new LibyanWingsAirline($credentials),
            'NB', 'Berniq' => new BerniqAirline($credentials),
            '5S', 'GlobalAir' => new GlobalAirline($credentials),
            'FQ', 'FlyCrown' => new CrownAirline($credentials),
            'LB', 'LibyanExpress' => new LibyaExpressAirline($credentials),
            default => throw new Exception("Unsupported Videcom airline code: {$config->airline_code}"),
        };
    }
}
