<?php

namespace App\Actions\Finance;

use App\DTOs\Finance\FinancialSourceData;
use App\Models\DefaultAgencySetting;
use App\Models\Tenant;
use App\Models\Tenant\AgencySetting;
use App\Models\TenantProvider;

class DetermineFinancialSource
{
    /**
     * Determine the financial source for an order item based on its airline code and currency.
     *
     * Decision logic (in priority order):
     * 1. If agency_settings.force_use_default_agency is true → master_agency_supply
     * 2. If agency_settings.can_use_own_airline_credentials is false → master_agency_supply
     * 3. If default_agency_settings restricts this airline code → master_agency_supply
     * 4. If an active TenantProvider exists and tenant uses own credentials → own_credentials
     * 5. Otherwise → master_agency_supply
     *
     * When master_agency_supply is returned, the DTO carries the default agency's
     * tenant ID and the per-agency master commission rate from agency_settings.
     */
    public function execute(string $airlineCode, string $currency): FinancialSourceData
    {
        $provider = $this->resolveProvider($airlineCode, $currency);
        $currentTenant = tenant();
        $agencySettings = AgencySetting::current();

        // Force use default agency regardless of own credentials.
        if ($agencySettings->isForcedToUseDefaultAgency()) {
            return $this->resolveMasterSupply($provider, $agencySettings, $airlineCode);
        }

        // Agency is not allowed to use own credentials.
        if (! $agencySettings->canUseOwnAirlineCredentials()) {
            return $this->resolveMasterSupply($provider, $agencySettings, $airlineCode);
        }

        // Check if the default agency restricts this airline code.
        $defaultAgency = Tenant::getDefaultAgency();
        if ($defaultAgency) {
            $defaultAgencySettings = DefaultAgencySetting::forDefaultAgency($defaultAgency->id);
            if (! $defaultAgencySettings->isAirlineAllowed($airlineCode)) {
                return $this->resolveMasterSupply($provider, $agencySettings, $airlineCode);
            }
        }

        // Check if tenant has own credentials for this airline.
        $usesOwnCredentials = $provider !== null
            && (bool) $currentTenant?->usesOwnAirlineCredentials($airlineCode);

        if ($usesOwnCredentials) {
            return new FinancialSourceData(
                type: 'own_credentials',
                provider: $provider,
            );
        }

        return $this->resolveMasterSupply($provider, $agencySettings, $airlineCode);
    }

    /**
     * Resolve the master agency supply DTO with per-agency commission rate.
     */
    protected function resolveMasterSupply(
        ?TenantProvider $provider,
        AgencySetting $agencySettings,
        string $airlineCode,
    ): FinancialSourceData {
        $defaultAgencyTenantId = $agencySettings->default_agency_tenant_id;

        // Fall back to the system default agency if no per-agency override.
        if ($defaultAgencyTenantId === null) {
            $defaultAgency = Tenant::getDefaultAgency();
            $defaultAgencyTenantId = $defaultAgency?->id;
        }

        // Use per-agency commission rate from tenant agency_settings.
        $masterCommissionRate = $agencySettings->getMasterCommissionPercent();

        return new FinancialSourceData(
            type: 'master_agency_supply',
            provider: $provider,
            defaultAgencyTenantId: $defaultAgencyTenantId,
            masterCommissionRate: $masterCommissionRate,
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
