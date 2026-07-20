<?php

namespace App\Services\ESim;

use App\DTOs\ESim\ESimPackage;
use App\Models\Tenant\TenantEsimProvider;
use Illuminate\Support\Facades\Cache;

class ESimCatalogueService
{
    public function __construct(
        protected ESimProviderManager $providerManager,
        protected AirportCountryResolver $airportCountryResolver,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function packagesForCountry(string $countryIso2, bool $useCache = false): array
    {
        $iso = strtoupper(trim($countryIso2));

        if ($iso === '') {
            return [];
        }

        if ($useCache) {
            /** @var list<ESimPackage> $packages */
            $packages = Cache::remember(
                "esim_airport_packages_{$iso}",
                now()->addMinutes(30),
                fn (): array => $this->providerManager->provider()->catalogue(['country' => $iso]),
            );
        } else {
            $packages = $this->providerManager->provider()->catalogue(['country' => $iso]);
        }

        return $this->presentPackages($packages);
    }

    /**
     * @return array{airport: array<string, mixed>|null, packages: list<array<string, mixed>>}
     */
    public function packagesForAirport(string $iata): array
    {
        $airportContext = $this->airportCountryResolver->airportContext($iata);

        if ($airportContext === null || empty($airportContext['country_iso'])) {
            return [
                'airport' => $airportContext,
                'packages' => [],
            ];
        }

        return [
            'airport' => $airportContext,
            'packages' => $this->packagesForCountry((string) $airportContext['country_iso'], useCache: true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function networksForCountry(string $countryIso2): array
    {
        $iso = strtoupper(trim($countryIso2));

        if ($iso === '') {
            return [];
        }

        return $this->providerManager->provider()->networks($iso);
    }

    /**
     * @param  array<string, mixed>  $search
     * @return array<string, mixed>
     */
    public function resolvePackageData(string $packageId, array $search): array
    {
        $filters = array_filter([
            'country' => (string) ($search['country'] ?? ''),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $packages = $this->providerManager->provider()->catalogue($filters);
        $provider = $this->providerManager->activeProvider();

        foreach ($packages as $package) {
            if ($package->id === $packageId) {
                $presented = $package->toArray();

                if ($provider instanceof TenantEsimProvider) {
                    $presented = array_merge($presented, $provider->presentUsdPrice((float) $package->price));
                }

                return $presented;
            }
        }

        $bundle = $this->providerManager->provider()->bundles($packageId);
        $usdPrice = (float) ($bundle['price'] ?? 0);
        $presented = array_merge($bundle, [
            'id' => $packageId,
            'price' => $usdPrice,
            'currency' => 'USD',
        ]);

        if ($provider instanceof TenantEsimProvider) {
            $presented = array_merge($presented, $provider->presentUsdPrice($usdPrice));
        }

        return $presented;
    }

    /**
     * @return array<string, mixed>
     */
    public function activeProviderSource(): array
    {
        return $this->providerManager->activeProviderSource();
    }

    /**
     * @param  list<ESimPackage>  $packages
     * @return list<array<string, mixed>>
     */
    private function presentPackages(array $packages): array
    {
        $providerSource = $this->activeProviderSource();
        $provider = $this->providerManager->activeProvider();

        return array_map(
            function (ESimPackage $package) use ($providerSource, $provider): array {
                $presented = $package->toArray();

                if ($provider instanceof TenantEsimProvider) {
                    $converted = $provider->presentUsdPrice((float) $package->price);
                    $presented = array_merge($presented, $converted);
                }

                return array_merge($presented, [
                    'provider_source' => $providerSource,
                ]);
            },
            $packages,
        );
    }
}
