<?php

namespace App\Actions\Finance;

use App\DTOs\Finance\FinancialSourceData;
use App\Models\TenantProvider;

class DetermineFinancialSource
{
    /**
     * Determine the financial source for an order item based on its airline code and currency.
     *
     * Returns 'own_credentials' when an active TenantProvider exists for the airline and the
     * current tenant is configured to use its own airline credentials. Returns 'master_supply'
     * in all other cases (no provider found, provider inactive, or tenant uses master supply).
     */
    public function execute(string $airlineCode, string $currency): FinancialSourceData
    {
        $provider = $this->resolveProvider($airlineCode, $currency);

        $usesOwnCredentials = $provider !== null
            && (bool) tenant()?->usesOwnAirlineCredentials($airlineCode);

        return new FinancialSourceData(
            type: $usesOwnCredentials ? 'own_credentials' : 'master_supply',
            provider: $provider,
        );
    }

    protected function resolveProvider(string $airlineCode, string $currency): ?TenantProvider
    {
        if ($airlineCode === '') {
            return null;
        }

        return TenantProvider::query()
            ->where('airline_code', strtoupper($airlineCode))
            ->where('is_active', true)
            ->get()
            ->first(function (TenantProvider $provider) use ($currency): bool {
                $providerCurrency = strtoupper((string) data_get($provider->credentials, 'currency', ''));

                return $providerCurrency === '' || $providerCurrency === strtoupper($currency);
            });
    }
}
