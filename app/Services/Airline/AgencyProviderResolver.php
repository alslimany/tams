<?php

namespace App\Services\Airline;

use App\Models\ProviderAllocation;
use App\Models\Tenant;
use App\Models\Tenant\AgencySetting;
use App\Models\TenantProvider;
use App\Services\AgencyNetwork\MerchantProviderAllocationResolver;
use App\Services\AgencyNetwork\ProviderSourceSelector;

class AgencyProviderResolver
{
    public function __construct(
        protected MerchantProviderAllocationResolver $networkAllocationResolver,
        protected ProviderSourceSelector $sourceSelector,
    ) {}

    /**
     * Resolve the appropriate TenantProvider for a given airline code.
     *
     * Decision logic (in priority order):
     * 1. If agency_settings.force_use_default_agency is true → prefer agency network allocation, then deprecated default agency fallback
     * 2. If agency_settings.can_use_own_airline_credentials is false → prefer agency network allocation, then deprecated default agency fallback
     * 3. If agency has own provider for this airline → use agency's own provider
     * 4. Otherwise → deprecated default agency provider fallback
     *
     * @return array<string, mixed>
     */
    public function resolve(string $airlineCode): array
    {
        $agencySettings = AgencySetting::current();
        $currentTenantId = tenant()?->id;

        if ($agencySettings->isForcedToUseDefaultAgency() || ! $agencySettings->canUseOwnAirlineCredentials()) {
            $networkProvider = $this->resolveNetworkProviderForCurrentTenant($airlineCode);

            if ($networkProvider['provider'] instanceof TenantProvider) {
                return $networkProvider;
            }

            return $this->deprecatedDefaultAgencyResult($airlineCode, $currentTenantId);
        }

        $ownProvider = $this->getAgencyProvider($airlineCode);

        if ($ownProvider) {
            return array_merge($this->sourceSelector->own($ownProvider), [
                'provider' => $ownProvider,
                'is_using_master_agency' => false,
                'is_default_agency_deprecated' => false,
                'resolved_tenant_id' => $currentTenantId,
            ]);
        }

        return $this->deprecatedDefaultAgencyResult($airlineCode, $currentTenantId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function deprecatedDefaultAgencyResult(string $airlineCode, ?string $fallbackTenantId): array
    {
        $defaultAgency = Tenant::getDefaultAgency();
        $defaultProvider = $this->getDefaultAgencyProvider($airlineCode);

        $resolvedTenantId = $defaultProvider instanceof TenantProvider
            ? $defaultAgency?->id
            : $fallbackTenantId;

        $sourceMetadata = $defaultProvider instanceof TenantProvider && $defaultAgency instanceof Tenant
            ? $this->sourceSelector->defaultAgency($defaultProvider, (string) $defaultAgency->id)
            : [
                'source_type' => ProviderSourceSelector::SourceDefaultAgency,
                'provider_selector' => null,
                'source_agency_tenant_id' => null,
                'merchant_tenant_id' => null,
                'network_membership_id' => null,
                'provider_allocation_id' => null,
                'source_provider_model' => null,
                'source_provider_id' => null,
            ];

        return array_merge($sourceMetadata, [
            'provider' => $defaultProvider,
            'is_using_master_agency' => $defaultProvider instanceof TenantProvider,
            'is_default_agency_deprecated' => true,
            'resolved_tenant_id' => $resolvedTenantId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveNetworkProviderForCurrentTenant(string $airlineCode): array
    {
        $tenantId = tenant()?->id;
        if ($tenantId === null) {
            return ['provider' => null];
        }

        $allocation = $this->networkAllocationResolver
            ->forMerchant($tenantId)
            ->first(fn (ProviderAllocation $allocation): bool => $allocation->provider_type === 'airline'
                && $allocation->provider_identity === strtoupper($airlineCode));

        if (! $allocation instanceof ProviderAllocation) {
            return ['provider' => null];
        }

        return array_merge($this->resolveNetworkProviderAllocation($allocation), [
            'is_default_agency_deprecated' => false,
        ]);
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

    /**
     * Resolve a provider that belongs to an agency network allocation.
     *
     * @return array{
     *     provider: ?TenantProvider,
     *     source_type: string,
     *     is_using_master_agency: bool,
     *     resolved_tenant_id: string|null,
     *     source_agency_tenant_id: string|null,
     *     merchant_tenant_id: string|null,
     *     network_membership_id: int|null,
     *     provider_allocation_id: int|null
     * }
     */
    public function resolveNetworkProviderAllocation(ProviderAllocation $allocation): array
    {
        $metadata = array_merge($this->sourceSelector->agencyNetwork($allocation), [
            'provider' => null,
            'is_using_master_agency' => false,
            'resolved_tenant_id' => null,
            'is_default_agency_deprecated' => false,
        ]);

        if ($allocation->status !== ProviderAllocation::StatusActive) {
            return $metadata;
        }

        if ($allocation->networkMembership?->status !== \App\Models\NetworkMembership::StatusActive) {
            return $metadata;
        }

        if ($allocation->source_provider_model !== TenantProvider::class) {
            return $metadata;
        }

        $sourceAgency = Tenant::query()->find($allocation->agency_tenant_id);
        if (! $sourceAgency instanceof Tenant) {
            return $metadata;
        }

        $provider = $sourceAgency->run(function () use ($allocation): ?TenantProvider {
            return TenantProvider::query()
                ->whereKey($allocation->source_provider_id)
                ->where('is_active', true)
                ->first();
        });

        return array_merge($metadata, [
            'provider' => $provider,
            'resolved_tenant_id' => $provider instanceof TenantProvider ? $allocation->agency_tenant_id : null,
        ]);
    }
}
