<?php

namespace App\Services\Airline;

use App\Models\Tenant;
use App\Models\TenantProvider;

class AirlineProviderLocatorService
{
    /**
     * @return array{tenant_id:string,provider_id:int}|null
     */
    public function locateActiveVidecomProvider(string $airlineCode): ?array
    {
        $targetAirlineCode = strtoupper(trim($airlineCode));

        foreach (Tenant::query()->get(['id']) as $tenant) {
            try {
                /** @var array{id:int}|null $providerData */
                $providerData = $tenant->run(function () use ($targetAirlineCode): ?array {
                    $provider = TenantProvider::query()
                        ->where('is_active', true)
                        ->where('provider_type', 'videcom')
                        ->where('airline_code', $targetAirlineCode)
                        ->first(['id']);

                    if (! $provider) {
                        return null;
                    }

                    return ['id' => (int) $provider->id];
                });

                if ($providerData) {
                    return [
                        'tenant_id' => (string) $tenant->id,
                        'provider_id' => (int) $providerData['id'],
                    ];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
