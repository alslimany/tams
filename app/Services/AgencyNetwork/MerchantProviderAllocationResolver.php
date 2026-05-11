<?php

namespace App\Services\AgencyNetwork;

use App\Models\NetworkMembership;
use App\Models\ProviderAllocation;
use Illuminate\Support\Collection;

class MerchantProviderAllocationResolver
{
    public function __construct(
        protected ProviderSourceSelector $sourceSelector,
    ) {}

    /**
     * @return Collection<int, ProviderAllocation>
     */
    public function forMerchant(string $merchantTenantId): Collection
    {
        return ProviderAllocation::query()
            ->with('networkMembership')
            ->where('merchant_tenant_id', $merchantTenantId)
            ->active()
            ->offered()
            ->whereHas('networkMembership', function ($query) use ($merchantTenantId): void {
                $query->where('merchant_tenant_id', $merchantTenantId)
                    ->where('status', NetworkMembership::StatusActive);
            })
            ->orderBy('provider_type')
            ->orderBy('provider_driver')
            ->orderBy('provider_identity')
            ->get();
    }

    public function forMerchantProvider(
        string $merchantTenantId,
        string $providerType,
        string $providerDriver,
        string $providerIdentity,
    ): ?ProviderAllocation {
        return $this->forMerchant($merchantTenantId)
            ->first(fn (ProviderAllocation $allocation): bool => $allocation->provider_type === strtolower($providerType)
                && $allocation->provider_driver === strtolower($providerDriver)
                && $allocation->provider_identity === strtoupper($providerIdentity));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function metadataForMerchant(string $merchantTenantId): Collection
    {
        return $this->forMerchant($merchantTenantId)
            ->map(fn (ProviderAllocation $allocation): array => $this->metadataForAllocation($allocation))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataForAllocation(ProviderAllocation $allocation): array
    {
        return $this->sourceSelector->agencyNetwork($allocation);
    }
}
