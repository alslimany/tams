<?php

namespace App\Services\Hotels;

use App\Contracts\Hotels\HotelProviderInterface;
use App\Models\ProviderAllocation;
use App\Models\Tenant\TenantHotelProvider;
use App\Services\AgencyNetwork\MerchantProviderAllocationResolver;
use App\Services\AgencyNetwork\ProviderSourceResolver;
use App\Services\AgencyNetwork\ProviderSourceSelector;

class HotelProviderManager
{
    public function __construct(
        protected MerchantProviderAllocationResolver $allocationResolver,
        protected ProviderSourceResolver $sourceResolver,
    ) {}

    public function activeProvider(): ?TenantHotelProvider
    {
        $networkProvider = $this->activeNetworkProviderWithSource();

        if ($networkProvider['provider'] instanceof TenantHotelProvider) {
            return $networkProvider['provider'];
        }

        return TenantHotelProvider::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeProviderSource(): ?array
    {
        return $this->activeProviderWithSource()['source'];
    }

    /**
     * @return array{provider: ?TenantHotelProvider, source: array<string, mixed>|null}
     */
    public function activeProviderWithSource(): array
    {
        $networkProvider = $this->activeNetworkProviderWithSource();

        if ($networkProvider['provider'] instanceof TenantHotelProvider) {
            return $networkProvider;
        }

        $localProvider = TenantHotelProvider::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        if ($localProvider instanceof TenantHotelProvider) {
            return [
                'provider' => $localProvider,
                'source' => [
                    'source_type' => ProviderSourceSelector::SourceOwn,
                    'provider_selector' => "own:{$localProvider->id}",
                    'source_agency_tenant_id' => null,
                    'merchant_tenant_id' => null,
                    'network_membership_id' => null,
                    'provider_allocation_id' => null,
                    'provider_type' => 'hotel',
                    'provider_driver' => $localProvider->provider_type,
                    'provider_identity' => $localProvider->provider_type,
                    'source_provider_model' => TenantHotelProvider::class,
                    'source_provider_id' => $localProvider->id,
                    'resolved_tenant_id' => tenant()?->id,
                ],
            ];
        }

        return ['provider' => null, 'source' => null];
    }

    public function provider(): HotelProviderInterface
    {
        $provider = $this->activeProvider();

        if (! $provider instanceof TenantHotelProvider) {
            throw new HotelApiException('Hotel provider is not configured.');
        }

        return HotelProviderFactory::make($provider);
    }

    /**
     * @return array{provider: ?TenantHotelProvider, source: array<string, mixed>|null}
     */
    protected function activeNetworkProviderWithSource(): array
    {
        $merchantTenantId = tenant()?->id;

        if (! is_string($merchantTenantId) || $merchantTenantId === '') {
            return ['provider' => null, 'source' => null];
        }

        $allocation = $this->allocationResolver->forMerchant($merchantTenantId)
            ->first(fn (ProviderAllocation $allocation): bool => $allocation->provider_type === 'hotel'
                && $allocation->source_provider_model === TenantHotelProvider::class);

        if (! $allocation instanceof ProviderAllocation) {
            return ['provider' => null, 'source' => null];
        }

        $resolved = $this->sourceResolver->resolve("agency_network:{$allocation->id}");
        $provider = $resolved['provider'] ?? null;

        return [
            'provider' => $provider instanceof TenantHotelProvider ? $provider : null,
            'source' => $resolved,
        ];
    }
}
