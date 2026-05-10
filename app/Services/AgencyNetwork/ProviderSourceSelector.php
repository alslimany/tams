<?php

namespace App\Services\AgencyNetwork;

use App\Models\ProviderAllocation;
use App\Models\TenantProvider;

class ProviderSourceSelector
{
    public const SourceOwn = 'own';

    public const SourceDefaultAgency = 'default_agency';

    public const SourceAgencyNetwork = 'agency_network';

    /**
     * @return array<string, mixed>
     */
    public function own(TenantProvider $provider): array
    {
        return [
            'source_type' => self::SourceOwn,
            'provider_selector' => $this->ownSelector((int) $provider->id),
            'source_agency_tenant_id' => null,
            'merchant_tenant_id' => null,
            'network_membership_id' => null,
            'provider_allocation_id' => null,
            'source_provider_model' => TenantProvider::class,
            'source_provider_id' => (int) $provider->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultAgency(TenantProvider $provider, string $agencyTenantId): array
    {
        return [
            'source_type' => self::SourceDefaultAgency,
            'provider_selector' => $this->defaultAgencySelector($agencyTenantId, (int) $provider->id),
            'source_agency_tenant_id' => $agencyTenantId,
            'merchant_tenant_id' => null,
            'network_membership_id' => null,
            'provider_allocation_id' => null,
            'source_provider_model' => TenantProvider::class,
            'source_provider_id' => (int) $provider->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function agencyNetwork(ProviderAllocation $allocation): array
    {
        return [
            'source_type' => self::SourceAgencyNetwork,
            'provider_selector' => $this->agencyNetworkSelector((int) $allocation->id),
            'source_agency_tenant_id' => $allocation->agency_tenant_id,
            'merchant_tenant_id' => $allocation->merchant_tenant_id,
            'network_membership_id' => $allocation->network_membership_id,
            'provider_allocation_id' => $allocation->id,
            'provider_type' => $allocation->provider_type,
            'provider_driver' => $allocation->provider_driver,
            'provider_identity' => $allocation->provider_identity,
            'source_provider_model' => $allocation->source_provider_model,
            'source_provider_id' => $allocation->source_provider_id,
        ];
    }

    public function ownSelector(int $providerId): string
    {
        return "own:{$providerId}";
    }

    public function defaultAgencySelector(string $agencyTenantId, int $providerId): string
    {
        return "default_agency:{$agencyTenantId}:{$providerId}";
    }

    public function agencyNetworkSelector(int $allocationId): string
    {
        return "agency_network:{$allocationId}";
    }
}
