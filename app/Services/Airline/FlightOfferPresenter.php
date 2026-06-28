<?php

namespace App\Services\Airline;

use App\DTOs\Airline\FlightOption;
use App\Support\AirlineLogoUrl;
use App\Support\Iso8601Duration;

class FlightOfferPresenter
{
    public function __construct(
        private readonly FlightDurationCalculator $durationCalculator,
    ) {}

    /**
     * @param  array<string, mixed>|FlightOption  $offer
     * @return array<string, mixed>
     */
    public function present(array|FlightOption $offer, bool $forApi = false): array
    {
        $data = $offer instanceof FlightOption ? $offer->toArray() : $offer;
        $pricing = is_array($data['pricing'] ?? null) ? $data['pricing'] : [];

        $data['baggage'] = [
            'hold_weight' => $pricing['hold_weight'] ?? null,
            'hand_weight' => $pricing['hand_weight'] ?? null,
            'hold_pieces' => $pricing['hold_pieces'] ?? null,
        ];

        $data['airline_logo_url'] = AirlineLogoUrl::forCode((string) ($data['airline_code'] ?? ''));

        if (! empty($pricing['fare_id'])) {
            $data['fare_id'] = $pricing['fare_id'];
        }

        $data = $this->applyTimezoneAwareDurations($data);

        if ($forApi) {
            $data = $this->applyIso8601Durations($data);
        }

        return $data;
    }

    /**
     * @param  iterable<int, array<string, mixed>|FlightOption>  $offers
     * @return list<array<string, mixed>>
     */
    public function presentMany(iterable $offers, bool $forApi = false): array
    {
        $presented = [];

        foreach ($offers as $offer) {
            $presented[] = $this->present($offer, $forApi);
        }

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyTimezoneAwareDurations(array $data): array
    {
        if (! is_array($data['segments'] ?? null)) {
            return $data;
        }

        $airportCodes = [];
        foreach ($data['segments'] as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            if (! empty($segment['departure_airport'])) {
                $airportCodes[] = (string) $segment['departure_airport'];
            }

            if (! empty($segment['arrival_airport'])) {
                $airportCodes[] = (string) $segment['arrival_airport'];
            }
        }

        $this->durationCalculator->preloadTimezones($airportCodes);

        $totalMinutes = 0;

        foreach ($data['segments'] as $index => $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $minutes = $this->durationCalculator->minutesBetween(
                (string) ($segment['departure_airport'] ?? $data['departure_airport'] ?? ''),
                (string) ($segment['arrival_airport'] ?? $data['arrival_airport'] ?? ''),
                (string) ($segment['departure_time'] ?? $data['departure_time'] ?? ''),
                (string) ($segment['arrival_time'] ?? $data['arrival_time'] ?? ''),
                isset($segment['duration']) ? (int) $segment['duration'] : null,
            );

            $data['segments'][$index]['duration'] = $minutes;
            $totalMinutes += $minutes;
        }

        if ($totalMinutes > 0) {
            $data['duration'] = $totalMinutes;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyIso8601Durations(array $data): array
    {
        if (! is_array($data['segments'] ?? null)) {
            return $data;
        }

        $totalMinutes = 0;

        foreach ($data['segments'] as $index => $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $minutes = (int) ($segment['duration'] ?? 0);
            $totalMinutes += $minutes;
            $data['segments'][$index]['duration'] = Iso8601Duration::fromMinutes($minutes);
        }

        $data['duration'] = Iso8601Duration::fromMinutes($totalMinutes);

        return $data;
    }
}
