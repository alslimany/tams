<?php

namespace App\Services\Airline;

use App\Services\Airline\Videcom\VidecomAncillaryCatalog;
use Exception;

class FlightBookingPricing
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function mapItinerary(array $flight, string $reservationType = 'NN'): array
    {
        $itinerary = $flight['segments'] ?? [$flight];

        return array_map(function (array $segment) use ($reservationType): array {
            return [
                'flt_no' => $segment['flight_number'] ?? '000',
                'class' => $segment['class'] ?? 'Y',
                'date' => $segment['departure_time'] ?? now(),
                'origin' => $segment['departure_airport'] ?? 'XXX',
                'dest' => $segment['arrival_airport'] ?? 'XXX',
                'reservation_type' => $reservationType,
            ];
        }, $itinerary);
    }

    /**
     * @return array{total: float, currency: string}
     */
    public function baseFareFromFlight(array $flight): array
    {
        $pricing = $flight['pricing'] ?? [];

        if (is_array($pricing) && array_is_list($pricing)) {
            return [
                'total' => (float) collect($pricing)->sum(fn (array $price): float => (float) ($price['total'] ?? 0)),
                'currency' => (string) ($pricing[0]['currency'] ?? 'USD'),
            ];
        }

        return [
            'total' => (float) ($pricing['total'] ?? 0),
            'currency' => (string) ($pricing['currency'] ?? 'USD'),
        ];
    }

    /**
     * @param  array<int, array<int|string, string|null>>  $seatSelections
     * @return array{lines: list<array<string, mixed>>, total: float}
     */
    public function calculateSeatSelectionTotal(mixed $provider, array $flight, array $seatSelections): array
    {
        if ($seatSelections === [] || ! is_object($provider) || ! method_exists($provider, 'getSeatMap')) {
            return ['lines' => [], 'total' => 0.0];
        }

        $segments = $flight['segments'] ?? [$flight];
        $lines = [];
        $total = 0.0;
        $seatMapCache = [];

        foreach ($seatSelections as $passengerIndex => $segmentSeats) {
            if (! is_array($segmentSeats)) {
                continue;
            }

            foreach ($segmentSeats as $segmentNumber => $seatCode) {
                $seatCode = strtoupper(trim((string) $seatCode));

                if ($seatCode === '') {
                    continue;
                }

                $segmentIndex = max(0, ((int) $segmentNumber) - 1);
                $segment = $segments[$segmentIndex] ?? $segments[0] ?? null;

                if (! is_array($segment)) {
                    continue;
                }

                $flightNumber = (string) ($segment['flight_number'] ?? '');
                $flightDate = (string) ($segment['departure_time'] ?? $segment['date'] ?? '');
                $cacheKey = "{$flightNumber}|{$flightDate}";

                if (! isset($seatMapCache[$cacheKey])) {
                    try {
                        $seatMapCache[$cacheKey] = $provider->getSeatMap($flightNumber, $flightDate);
                    } catch (Exception) {
                        $seatMapCache[$cacheKey] = ['seats' => []];
                    }
                }

                $seatPrice = $this->findSeatPrice($seatMapCache[$cacheKey], $seatCode);
                $total += $seatPrice;

                $lines[] = [
                    'passenger_index' => (int) $passengerIndex,
                    'segment' => (int) $segmentNumber,
                    'seat' => $seatCode,
                    'price' => $seatPrice,
                ];
            }
        }

        return [
            'lines' => $lines,
            'total' => $total,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $selectedServices
     * @return array{lines: list<array<string, mixed>>, total: float}
     */
    public function calculateAncillaryTotal(
        mixed $provider,
        array $flight,
        array $selectedServices,
        int $passengerCount,
        array $searchParams = [],
    ): array {
        if ($selectedServices === [] || ! is_object($provider) || ! method_exists($provider, 'getAncillaryCatalog')) {
            return ['lines' => [], 'total' => 0.0];
        }

        $catalog = $provider->getAncillaryCatalog($flight, $searchParams);
        $segmentCount = count($flight['segments'] ?? [$flight]);

        $summary = VidecomAncillaryCatalog::selectedTotals(
            $catalog,
            $selectedServices,
            $passengerCount,
            max(1, $segmentCount),
        );

        return [
            'lines' => $summary['lines'] ?? [],
            'total' => (float) ($summary['total'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $extras
     * @param  list<array<string, mixed>>  $passengers
     * @return array<string, mixed>
     */
    public function normalizeExtras(array $extras, array $passengers): array
    {
        $normalized = $extras;

        if (isset($normalized['selected_services']) && is_array($normalized['selected_services'])) {
            $normalized['selected_services'] = array_map(fn (array $selection): array => [
                'code' => $selection['code'] ?? null,
                'quantity' => $selection['quantity'] ?? null,
                'passengers' => $selection['passengers'] ?? [],
            ], $normalized['selected_services']);
        }

        $normalized['include_docs'] = (bool) ($normalized['include_docs'] ?? false)
            || $this->passengersContainPassportDetails($passengers);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $flight
     * @param  list<array<string, mixed>>  $passengers
     * @param  array<string, mixed>  $extras
     * @param  array<string, mixed>  $searchParams
     * @return array{
     *     currency: string,
     *     base_fare: float,
     *     seats: array{lines: list<array<string, mixed>>, total: float},
     *     ancillaries: array{lines: list<array<string, mixed>>, total: float},
     *     grand_total: float,
     *     extras: array<string, mixed>
     * }
     */
    public function summarize(
        mixed $provider,
        array $flight,
        array $passengers,
        array $extras = [],
        array $searchParams = [],
    ): array {
        $baseFare = $this->baseFareFromFlight($flight);
        $extras = $this->normalizeExtras($extras, $passengers);
        $passengerCount = count($passengers);

        $seats = $this->calculateSeatSelectionTotal(
            $provider,
            $flight,
            is_array($extras['seats'] ?? null) ? $extras['seats'] : [],
        );

        $ancillaries = $this->calculateAncillaryTotal(
            $provider,
            $flight,
            is_array($extras['selected_services'] ?? null) ? $extras['selected_services'] : [],
            $passengerCount,
            $searchParams,
        );

        $grandTotal = $baseFare['total'] + $seats['total'] + $ancillaries['total'];

        return [
            'currency' => strtoupper($baseFare['currency']),
            'base_fare' => $baseFare['total'],
            'seats' => $seats,
            'ancillaries' => $ancillaries,
            'grand_total' => $grandTotal,
            'extras' => $extras,
        ];
    }

    /**
     * @param  array<string, mixed>  $seatMap
     */
    protected function findSeatPrice(array $seatMap, string $seatCode): float
    {
        foreach ($seatMap['seats'] ?? [] as $seat) {
            if (! is_array($seat)) {
                continue;
            }

            $code = strtoupper((string) ($seat['code'] ?? $seat['number'] ?? ''));

            if ($code === $seatCode) {
                return (float) ($seat['price'] ?? $seat['scprice'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * @param  list<array<string, mixed>>  $passengers
     */
    protected function passengersContainPassportDetails(array $passengers): bool
    {
        foreach ($passengers as $passenger) {
            if (! is_array($passenger)) {
                continue;
            }

            if (! empty($passenger['passport_number'])
                || ! empty($passenger['passport_expiry'])
                || ! empty($passenger['passport_issue_country'])
                || ! empty($passenger['nationality'])) {
                return true;
            }
        }

        return false;
    }
}
