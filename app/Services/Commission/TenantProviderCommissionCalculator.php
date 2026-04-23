<?php

namespace App\Services\Commission;

use App\Models\Airport;
use App\Models\TenantProvider;

class TenantProviderCommissionCalculator
{
    public function calculateForFormattedOrderDetails(TenantProvider $provider, array $orderDetails): float
    {
        if ($provider->provider_type !== 'videcom') {
            return 0.0;
        }

        $fareAmount = (float) data_get($orderDetails, 'total_fare', 0);
        if ($fareAmount <= 0) {
            return 0.0;
        }

        $rate = $this->resolveFlightTypeFromOrderDetails($orderDetails) === 'international'
            ? (float) ($provider->international_commission_rate ?? 0)
            : (float) ($provider->domestic_commission_rate ?? 0);

        if ($rate <= 0) {
            return 0.0;
        }

        return round($fareAmount * ($rate / 100), 2);
    }

    public function resolveFlightTypeFromOrderDetails(array $orderDetails): string
    {
        $itineraries = collect((array) data_get($orderDetails, 'itineraries', []));

        if ($itineraries->contains(fn (array $itinerary): bool => ($itinerary['is_international'] ?? null) === true)) {
            return 'international';
        }

        if ($itineraries->isNotEmpty() && $itineraries->every(fn (array $itinerary): bool => ($itinerary['is_international'] ?? null) === false)) {
            return 'domestic';
        }

        return $this->resolveFlightTypeFromSegments($itineraries->map(fn (array $itinerary): array => [
            'origin' => (string) ($itinerary['from'] ?? ''),
            'destination' => (string) ($itinerary['to'] ?? ''),
        ])->all());
    }

    /**
     * @param  array<int, array{origin: string, destination: string}>  $segments
     */
    public function resolveFlightTypeFromSegments(array $segments): string
    {
        $airportCodes = collect($segments)
            ->flatMap(fn (array $segment): array => [$segment['origin'], $segment['destination']])
            ->filter()
            ->map(fn (string $airportCode): string => strtoupper(trim($airportCode)))
            ->unique()
            ->values();

        if ($airportCodes->isEmpty()) {
            return 'domestic';
        }

        $airportMap = Airport::query()
            ->whereIn('iata_code', $airportCodes->all())
            ->get()
            ->keyBy(fn (Airport $airport): string => strtoupper((string) $airport->iata_code));

        foreach ($segments as $segment) {
            $origin = strtoupper(trim($segment['origin']));
            $destination = strtoupper(trim($segment['destination']));

            if ($origin === '' || $destination === '') {
                continue;
            }

            $originCountry = $this->resolveAirportCountry($airportMap->get($origin));
            $destinationCountry = $this->resolveAirportCountry($airportMap->get($destination));

            if ($originCountry !== null && $destinationCountry !== null && $originCountry !== $destinationCountry) {
                return 'international';
            }
        }

        return 'domestic';
    }

    protected function resolveAirportCountry(?Airport $airport): ?string
    {
        if (! $airport) {
            return null;
        }

        $countryCode = data_get($airport->data, 'country_code')
            ?? data_get($airport->data, 'countryCode')
            ?? data_get($airport->data, 'country.code')
            ?? $airport->getAttributes()['country_code'] ?? null;

        if (is_string($countryCode) && trim($countryCode) !== '') {
            return strtoupper(trim($countryCode));
        }

        $country = null;

        if (method_exists($airport, 'getTranslation')) {
            $country = $airport->getTranslation('country', 'en', false);
        }

        if (! $country) {
            $rawCountry = $airport->getAttributes()['country'] ?? null;

            if (is_string($rawCountry) && str_starts_with($rawCountry, '{')) {
                $decoded = json_decode($rawCountry, true);
                $country = $decoded['en'] ?? (is_array($decoded) ? reset($decoded) : null);
            } elseif (is_string($rawCountry)) {
                $country = $rawCountry;
            }
        }

        if (! is_string($country) || trim($country) === '') {
            return null;
        }

        return strtoupper(trim($country));
    }
}
