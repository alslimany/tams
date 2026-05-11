<?php

namespace App\Services\Insurance;

use App\Contracts\Insurance\InsuranceProviderInterface;
use App\Models\ProviderAllocation;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Services\AgencyNetwork\MerchantProviderAllocationResolver;
use App\Services\AgencyNetwork\ProviderSourceResolver;
use App\Services\AgencyNetwork\ProviderSourceSelector;
use App\Services\Insurance\Providers\AlBarakaProvider;
use RuntimeException;

class InsuranceProviderManager
{
    public function __construct(
        protected AlBarakaProvider $alBarakaProvider,
        protected MerchantProviderAllocationResolver $allocationResolver,
        protected ProviderSourceResolver $sourceResolver,
    ) {}

    public function provider(): InsuranceProviderInterface
    {
        $active = $this->activeProvider();

        return match ($active?->provider_type ?? 'albaraka') {
            'albaraka' => $this->alBarakaProvider,
            default => throw new RuntimeException('Unsupported insurance provider type.'),
        };
    }

    public function activeProvider(): ?TenantInsuranceProvider
    {
        return $this->activeProviderWithSource()['provider'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeProviderSource(): ?array
    {
        return $this->activeProviderWithSource()['source'];
    }

    /**
     * @return array{provider: ?TenantInsuranceProvider, source: array<string, mixed>|null}
     */
    public function activeProviderWithSource(): array
    {
        $networkProvider = $this->activeNetworkProviderWithSource();

        if ($networkProvider['provider'] instanceof TenantInsuranceProvider) {
            return $networkProvider;
        }

        $localProvider = TenantInsuranceProvider::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        if ($localProvider instanceof TenantInsuranceProvider) {
            return [
                'provider' => $localProvider,
                'source' => [
                    'source_type' => ProviderSourceSelector::SourceOwn,
                    'provider_selector' => "own:{$localProvider->id}",
                    'source_agency_tenant_id' => null,
                    'merchant_tenant_id' => null,
                    'network_membership_id' => null,
                    'provider_allocation_id' => null,
                    'provider_type' => 'insurance',
                    'provider_driver' => $localProvider->provider_type,
                    'provider_identity' => $localProvider->provider_type,
                    'source_provider_model' => TenantInsuranceProvider::class,
                    'source_provider_id' => $localProvider->id,
                    'resolved_tenant_id' => tenant()?->id,
                ],
            ];
        }

        return ['provider' => null, 'source' => null];
    }

    /**
     * @return array{provider: ?TenantInsuranceProvider, source: array<string, mixed>|null}
     */
    protected function activeNetworkProviderWithSource(): array
    {
        $merchantTenantId = tenant()?->id;

        if (! is_string($merchantTenantId) || $merchantTenantId === '') {
            return ['provider' => null, 'source' => null];
        }

        $allocation = $this->allocationResolver->forMerchant($merchantTenantId)
            ->first(fn (ProviderAllocation $allocation): bool => $allocation->provider_type === 'insurance'
                && $allocation->source_provider_model === TenantInsuranceProvider::class);

        if (! $allocation instanceof ProviderAllocation) {
            return ['provider' => null, 'source' => null];
        }

        $resolved = $this->sourceResolver->resolve("agency_network:{$allocation->id}");
        $provider = $resolved['provider'] ?? null;

        return [
            'provider' => $provider instanceof TenantInsuranceProvider ? $provider : null,
            'source' => $resolved,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function configuredProviders(): array
    {
        $providers = TenantInsuranceProvider::query()
            ->orderBy('name')
            ->get()
            ->map(fn (TenantInsuranceProvider $item): array => [
                'provider_type' => $item->provider_type,
                'name' => $item->name,
                'is_active' => (bool) $item->is_active,
                'source' => [
                    'source_type' => ProviderSourceSelector::SourceOwn,
                    'provider_selector' => "own:{$item->id}",
                ],
            ]);

        $merchantTenantId = tenant()?->id;

        if (is_string($merchantTenantId) && $merchantTenantId !== '') {
            $networkProviders = $this->allocationResolver->forMerchant($merchantTenantId)
                ->filter(fn (ProviderAllocation $allocation): bool => $allocation->provider_type === 'insurance'
                    && $allocation->source_provider_model === TenantInsuranceProvider::class)
                ->map(function (ProviderAllocation $allocation): array {
                    $source = $this->sourceResolver->resolve("agency_network:{$allocation->id}");
                    $provider = $source['provider'] ?? null;

                    return [
                        'provider_type' => $allocation->provider_driver,
                        'name' => (string) (data_get($allocation->metadata, 'display_name') ?: ($provider instanceof TenantInsuranceProvider ? $provider->name : $allocation->provider_identity)),
                        'is_active' => $provider instanceof TenantInsuranceProvider,
                        'source' => $source,
                    ];
                });

            $providers = $providers->concat($networkProviders);
        }

        return $providers->values()->all();
    }
}
