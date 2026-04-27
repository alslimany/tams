<?php

namespace App\Services\Airline;

use App\Models\Tenant;
use App\Models\Tenant\AgencySetting;
use App\Models\TenantProvider;

class AgencyProviderResolver
{
    /**
     * Resolve the appropriate TenantProvider for a given airline code.
     *
     * Decision logic (in priority order):
     * 1. If agency_settings.force_use_default_agency is true → use default agency's provider
     * 2. If agency_settings.can_use_own_airline_credentials is false → use default agency's provider
     * 3. If agency has own provider for this airline → use agency's own provider
     * 4. Otherwise → use default agency's provider (fallback)
     *
     * @return array{provider: ?TenantProvider, is_using_master_agency: bool, resolved_tenant_id: string|null}
     */
    public function resolve(string $airlineCode): array
    {
        $agencySettings = AgencySetting::current();
        $isUsingMasterAgency = false;
        $resolvedTenantId = tenant()?->id;

        // If forced to use default agency, or not allowed to use own credentials...
        if ($agencySettings->isForcedToUseDefaultAgency() || ! $agencySettings->canUseOwnAirlineCredentials()) {
            $provider = $this->getDefaultAgencyProvider($airlineCode);
            if ($provider) {
                $isUsingMasterAgency = true;
                $resolvedTenantId = $provider->tenant_id;
            }

            return [
                'provider' => $provider,
                'is_using_master_agency' => $isUsingMasterAgency,
                'resolved_tenant_id' => $resolvedTenantId,
            ];
        }

        // Try to use agency's own provider
        $ownProvider = $this->getAgencyProvider($airlineCode);

        if ($ownProvider) {
            return [
                'provider' => $ownProvider,
                'is_using_master_agency' => false,
                'resolved_tenant_id' => $ownProvider->tenant_id,
            ];
        }

        // Fallback to default agency provider
        $defaultProvider = $this->getDefaultAgencyProvider($airlineCode);
        if ($defaultProvider) {
            $isUsingMasterAgency = true;
            $resolvedTenantId = $defaultProvider->tenant_id;
        }

        return [
            'provider' => $defaultProvider,
            'is_using_master_agency' => $isUsingMasterAgency,
            'resolved_tenant_id' => $resolvedTenantId,
        ];
    }

    /**
     * Get the provider from the agency's own database.
     */
    protected function getAgencyProvider(string $airlineCode): ?TenantProvider
    {
        return TenantProvider::query()
            ->where('airline_code', strtoupper($airlineCode))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get the provider from the default master agency tenant.
     */
    protected function getDefaultAgencyProvider(string $airlineCode): ?TenantProvider
    {
        $defaultAgency = Tenant::getDefaultAgency();

        if (! $defaultAgency) {
            return null;
        }

        return $defaultAgency->run(function () use ($airlineCode): ?TenantProvider {
            return TenantProvider::query()
                ->where('airline_code', strtoupper($airlineCode))
                ->where('is_active', true)
                ->first();
        });
    }

    /**
     * Check if the agency is allowed to manage its own providers.
     */
    public function canManageOwnProviders(): bool
    {
        $settings = AgencySetting::current();

        return ! $settings->isForcedToUseDefaultAgency()
            && $settings->canUseOwnAirlineCredentials();
    }

    /**
     * Get the master commission percent for this agency.
     */
    public function getMasterCommissionPercent(): float
    {
        return AgencySetting::current()->getMasterCommissionPercent();
    }

    /**
     * Get the default agency tenant ID.
     */
    public function getDefaultAgencyTenantId(): ?string
    {
        $defaultAgency = Tenant::getDefaultAgency();

        return $defaultAgency?->id;
    }

    /**
     * Get all active providers based on agency settings.
     * When forced to use default agency, returns default agency's providers.
     * Otherwise, returns the agency's own providers.
     *
     * @return Collection<int, TenantProvider>
     */
    public function getAllActiveProviders(): \Illuminate\Support\Collection
    {
        try {
            $agencySettings = AgencySetting::current();
        } catch (\Throwable) {
            return $this->fallbackActiveProviders();
        }

        // If forced to use default agency, or not allowed to use own credentials...
        try {
            if ($agencySettings->isForcedToUseDefaultAgency() || ! $agencySettings->canUseOwnAirlineCredentials()) {
                return $this->getAllDefaultAgencyProviders();
            }
        } catch (\Throwable) {
            return $this->fallbackActiveProviders();
        }

        // Try to use agency's own providers
        $ownProviders = $this->getAllAgencyProviders();

        // If no own providers, fallback to default agency
        if ($ownProviders->isEmpty()) {
            return $this->getAllDefaultAgencyProviders();
        }

        return $ownProviders;
    }

    protected function fallbackActiveProviders(): \Illuminate\Support\Collection
    {
        $ownProviders = $this->getAllAgencyProviders();

        if ($ownProviders->isNotEmpty()) {
            return $ownProviders;
        }

        return $this->getAllDefaultAgencyProviders();
    }

    /**
     * Get all providers from the agency's own database.
     *
     * @return Collection<int, TenantProvider>
     */
    public function getAllAgencyProviders(): \Illuminate\Support\Collection
    {
        try {
            return TenantProvider::query()
                ->where('is_active', true)
                ->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Get all providers from the default master agency tenant.
     *
     * @return Collection<int, TenantProvider>
     */
    public function getAllDefaultAgencyProviders(): \Illuminate\Support\Collection
    {
        $defaultAgency = Tenant::getDefaultAgency();

        if (! $defaultAgency) {
            return collect();
        }

        try {
            return $defaultAgency->run(function (): \Illuminate\Support\Collection {
                return TenantProvider::query()
                    ->where('is_active', true)
                    ->get();
            });
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    /**
     * Find a provider by ID from the active providers.
     */
    public function findProviderById(int $providerId): ?TenantProvider
    {
        $providers = $this->getAllActiveProviders();

        return $providers->firstWhere('id', $providerId);
    }
}
