<?php

namespace App\Services\ESim;

use App\Contracts\ESim\ESimProviderInterface;
use App\Models\ProviderAllocation;
use App\Models\Tenant\TenantEsimProvider;
use App\Services\AgencyNetwork\MerchantProviderAllocationResolver;
use App\Services\AgencyNetwork\ProviderSourceResolver;
use App\Services\AgencyNetwork\ProviderSourceSelector;

class ESimProviderManager
{
    public function __construct(
        protected MerchantProviderAllocationResolver $allocationResolver,
        protected ProviderSourceResolver $sourceResolver,
    ) {}

    public function activeProvider(): ?TenantEsimProvider
    {
        $networkProvider = $this->activeNetworkProviderWithSource();

        if ($networkProvider['provider'] instanceof TenantEsimProvider) {
            return $networkProvider['provider'];
        }

        return TenantEsimProvider::query()
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
     * @return array{provider: ?TenantEsimProvider, source: array<string, mixed>|null}
     */
    public function activeProviderWithSource(): array
    {
        $networkProvider = $this->activeNetworkProviderWithSource();

        if ($networkProvider['provider'] instanceof TenantEsimProvider) {
            return $networkProvider;
        }

        $localProvider = TenantEsimProvider::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        if ($localProvider instanceof TenantEsimProvider) {
            return [
                'provider' => $localProvider,
                'source' => [
                    'source_type' => ProviderSourceSelector::SourceOwn,
                    'provider_selector' => "own:{$localProvider->id}",
                    'source_agency_tenant_id' => null,
                    'merchant_tenant_id' => null,
                    'network_membership_id' => null,
                    'provider_allocation_id' => null,
                    'provider_type' => 'esim',
                    'provider_driver' => $localProvider->provider_type,
                    'provider_identity' => $localProvider->provider_type,
                    'source_provider_model' => TenantEsimProvider::class,
                    'source_provider_id' => $localProvider->id,
                    'resolved_tenant_id' => tenant()?->id,
                ],
            ];
        }

        return ['provider' => null, 'source' => null];
    }

    public function provider(): ESimProviderInterface
    {
        $provider = $this->activeProvider();

        if (! $provider instanceof TenantEsimProvider) {
            throw new ESimApiException('eSIM provider is not configured.');
        }

        return ESimProviderFactory::make($provider);
    }

    /**
     * @return array{provider: ?TenantEsimProvider, source: array<string, mixed>|null}
     */
    protected function activeNetworkProviderWithSource(): array
    {
        $merchantTenantId = tenant()?->id;

        if (! is_string($merchantTenantId) || $merchantTenantId === '') {
            return ['provider' => null, 'source' => null];
        }

        $allocation = $this->allocationResolver->forMerchant($merchantTenantId)
            ->first(fn (ProviderAllocation $allocation): bool => $allocation->provider_type === 'esim'
                && $allocation->source_provider_model === TenantEsimProvider::class);

        if (! $allocation instanceof ProviderAllocation) {
            return ['provider' => null, 'source' => null];
        }

        $resolved = $this->sourceResolver->resolve("agency_network:{$allocation->id}");
        $provider = $resolved['provider'] ?? null;

        return [
            'provider' => $provider instanceof TenantEsimProvider ? $provider : null,
            'source' => $resolved,
        ];
    }
}
