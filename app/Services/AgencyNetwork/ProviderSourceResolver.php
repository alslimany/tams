<?php

namespace App\Services\AgencyNetwork;

use App\Models\NetworkMembership;
use App\Models\ProviderAllocation;
use App\Models\Tenant;
use App\Models\TenantProvider;

class ProviderSourceResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(?string $providerSelector): array
    {
        $emptyResult = $this->emptyResult();

        if (! is_string($providerSelector) || $providerSelector === '') {
            return $emptyResult;
        }

        $segments = explode(':', $providerSelector);

        return match ($segments[0] ?? null) {
            ProviderSourceSelector::SourceOwn => $this->resolveOwn($segments),
            ProviderSourceSelector::SourceDefaultAgency => $this->resolveDefaultAgency($segments),
            ProviderSourceSelector::SourceAgencyNetwork => $this->resolveAgencyNetwork($segments),
            default => $emptyResult,
        };
    }

    /**
     * @param  array<int, string>  $segments
     * @return array<string, mixed>
     */
    protected function resolveOwn(array $segments): array
    {
        $providerId = $this->positiveInteger($segments[1] ?? null);

        if ($providerId === null) {
            return $this->emptyResult(ProviderSourceSelector::SourceOwn);
        }

        $provider = TenantProvider::query()
            ->whereKey($providerId)
            ->where('is_active', true)
            ->first();

        return array_merge($this->ownMetadata($providerId), [
            'provider' => $provider,
            'resolved_tenant_id' => $provider instanceof TenantProvider ? tenant()?->id : null,
        ]);
    }

    /**
     * @param  array<int, string>  $segments
     * @return array<string, mixed>
     */
    protected function resolveDefaultAgency(array $segments): array
    {
        $agencyTenantId = $segments[1] ?? null;
        $providerId = $this->positiveInteger($segments[2] ?? null);

        if (! is_string($agencyTenantId) || $agencyTenantId === '' || $providerId === null) {
            return $this->emptyResult(ProviderSourceSelector::SourceDefaultAgency, is_string($agencyTenantId) ? $agencyTenantId : null, true);
        }

        $provider = $this->resolveTenantProvider($agencyTenantId, $providerId);

        return array_merge($this->defaultAgencyMetadata($agencyTenantId, $providerId), [
            'provider' => $provider,
            'resolved_tenant_id' => $provider instanceof TenantProvider ? $agencyTenantId : null,
        ]);
    }

    /**
     * @param  array<int, string>  $segments
     * @return array<string, mixed>
     */
    protected function resolveAgencyNetwork(array $segments): array
    {
        $allocationId = $this->positiveInteger($segments[1] ?? null);

        if ($allocationId === null) {
            return $this->emptyResult(ProviderSourceSelector::SourceAgencyNetwork);
        }

        $allocation = ProviderAllocation::query()
            ->with('networkMembership')
            ->find($allocationId);

        if (! $allocation instanceof ProviderAllocation) {
            return array_merge($this->emptyResult(ProviderSourceSelector::SourceAgencyNetwork), [
                'provider_allocation_id' => $allocationId,
            ]);
        }

        $metadata = $this->agencyNetworkMetadata($allocation);

        if ($allocation->status !== ProviderAllocation::StatusActive) {
            return $metadata;
        }

        if ($allocation->networkMembership?->status !== NetworkMembership::StatusActive) {
            return $metadata;
        }

        if ($allocation->source_provider_model !== TenantProvider::class) {
            return $metadata;
        }

        $provider = $this->resolveTenantProvider($allocation->agency_tenant_id, (int) $allocation->source_provider_id);

        return array_merge($metadata, [
            'provider' => $provider,
            'resolved_tenant_id' => $provider instanceof TenantProvider ? $allocation->agency_tenant_id : null,
        ]);
    }

    protected function resolveTenantProvider(string $tenantId, int $providerId): ?TenantProvider
    {
        $currentTenantId = tenant()?->id;
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            return null;
        }

        try {
            return $tenant->run(function () use ($providerId): ?TenantProvider {
                return TenantProvider::query()
                    ->whereKey($providerId)
                    ->where('is_active', true)
                    ->first();
            });
        } finally {
            if ($currentTenantId !== null) {
                $previousTenant = Tenant::query()->find($currentTenantId);

                if ($previousTenant instanceof Tenant) {
                    tenancy()->initialize($previousTenant);
                }
            } else {
                tenancy()->end();
            }
        }
    }

    protected function positiveInteger(?string $value): ?int
    {
        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyResult(?string $sourceType = null, ?string $sourceAgencyTenantId = null, bool $defaultAgencyDeprecated = false): array
    {
        return [
            'provider' => null,
            'source_type' => $sourceType,
            'provider_selector' => null,
            'source_agency_tenant_id' => $sourceAgencyTenantId,
            'merchant_tenant_id' => null,
            'network_membership_id' => null,
            'provider_allocation_id' => null,
            'provider_type' => null,
            'provider_driver' => null,
            'provider_identity' => null,
            'source_provider_model' => null,
            'source_provider_id' => null,
            'is_using_master_agency' => false,
            'is_default_agency_deprecated' => $defaultAgencyDeprecated,
            'resolved_tenant_id' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function ownMetadata(int $providerId): array
    {
        return array_merge($this->emptyResult(ProviderSourceSelector::SourceOwn), [
            'provider_selector' => "own:{$providerId}",
            'source_provider_model' => TenantProvider::class,
            'source_provider_id' => $providerId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultAgencyMetadata(string $agencyTenantId, int $providerId): array
    {
        return array_merge($this->emptyResult(ProviderSourceSelector::SourceDefaultAgency, $agencyTenantId, true), [
            'provider_selector' => "default_agency:{$agencyTenantId}:{$providerId}",
            'source_provider_model' => TenantProvider::class,
            'source_provider_id' => $providerId,
            'is_using_master_agency' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function agencyNetworkMetadata(ProviderAllocation $allocation): array
    {
        return array_merge($this->emptyResult(ProviderSourceSelector::SourceAgencyNetwork), [
            'provider_selector' => "agency_network:{$allocation->id}",
            'source_agency_tenant_id' => $allocation->agency_tenant_id,
            'merchant_tenant_id' => $allocation->merchant_tenant_id,
            'network_membership_id' => $allocation->network_membership_id,
            'provider_allocation_id' => $allocation->id,
            'provider_type' => $allocation->provider_type,
            'provider_driver' => $allocation->provider_driver,
            'provider_identity' => $allocation->provider_identity,
            'source_provider_model' => $allocation->source_provider_model,
            'source_provider_id' => $allocation->source_provider_id,
        ]);
    }
}
