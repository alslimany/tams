<?php

namespace App\Services\ESim;

use App\DTOs\ESim\ESimPackage;
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

        foreach ($packages as $package) {
            if ($package->id === $packageId) {
                return $package->toArray();
            }
        }

        $bundle = $this->providerManager->provider()->bundles($packageId);

        return array_merge($bundle, ['id' => $packageId]);
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

        return array_map(
            fn (ESimPackage $package): array => array_merge($package->toArray(), [
                'provider_source' => $providerSource,
            ]),
            $packages,
        );
    }
}
