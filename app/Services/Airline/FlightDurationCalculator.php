<?php

namespace App\Services\Airline;

use App\Models\Airport;
use Carbon\Carbon;
use Illuminate\Support\Str;

class FlightDurationCalculator
{
    /** @var array<string, string|null> */
    private array $timezoneCache = [];

    /**
     * Flight elapsed time in minutes, interpreting departure/arrival timestamps
     * in each airport's local timezone when available.
     */
    public function minutesBetween(
        string $departureAirport,
        string $arrivalAirport,
        string $departureTime,
        string $arrivalTime,
        ?int $fallbackMinutes = null,
    ): int {
        $departureAirport = strtoupper(trim($departureAirport));
        $arrivalAirport = strtoupper(trim($arrivalAirport));

        if ($departureTime === '' || $arrivalTime === '') {
            return max(0, $fallbackMinutes ?? 0);
        }

        $departureTimezone = $this->timezoneFor($departureAirport);
        $arrivalTimezone = $this->timezoneFor($arrivalAirport);

        if ($departureTimezone !== null && $arrivalTimezone !== null) {
            try {
                $departureUtc = Carbon::parse($departureTime, $departureTimezone)->utc();
                $arrivalUtc = Carbon::parse($arrivalTime, $arrivalTimezone)->utc();

                return (int) max(0, $departureUtc->diffInMinutes($arrivalUtc));
            } catch (\Throwable) {
                // Fall through to naive parsing below.
            }
        }

        if ($fallbackMinutes !== null && $fallbackMinutes > 0) {
            return $fallbackMinutes;
        }

        try {
            return (int) max(0, Carbon::parse($departureTime)->diffInMinutes(Carbon::parse($arrivalTime)));
        } catch (\Throwable) {
            return max(0, $fallbackMinutes ?? 0);
        }
    }

    /**
     * @param  list<string>  $iataCodes
     */
    public function preloadTimezones(array $iataCodes): void
    {
        $codes = collect($iataCodes)
            ->map(fn (string $code) => strtoupper(trim($code)))
            ->filter(fn (string $code) => $code !== '' && ! array_key_exists($code, $this->timezoneCache))
            ->unique()
            ->values()
            ->all();

        if ($codes === []) {
            return;
        }

        try {
            Airport::query()
                ->whereIn('iata_code', $codes)
                ->get(['iata_code', 'timezone'])
                ->each(function (Airport $airport): void {
                    $code = strtoupper((string) $airport->iata_code);
                    $this->timezoneCache[$code] = self::isValidTimezone($airport->timezone)
                        ? (string) $airport->timezone
                        : null;
                });
        } catch (\Throwable) {
            // Airport lookup unavailable — callers will fall back to provider minutes.
        }

        foreach ($codes as $code) {
            $this->timezoneCache[$code] ??= null;
        }
    }

    public function clearCache(): void
    {
        $this->timezoneCache = [];
    }

    private function timezoneFor(string $iataCode): ?string
    {
        if (! array_key_exists($iataCode, $this->timezoneCache)) {
            try {
                $timezone = Airport::query()
                    ->where('iata_code', $iataCode)
                    ->value('timezone');
            } catch (\Throwable) {
                $timezone = null;
            }

            $this->timezoneCache[$iataCode] = self::isValidTimezone($timezone)
                ? (string) $timezone
                : null;
        }

        return $this->timezoneCache[$iataCode];
    }

    public static function isValidTimezone(?string $timezone): bool
    {
        if ($timezone === null || trim($timezone) === '') {
            return false;
        }

        if (is_numeric($timezone)) {
            return false;
        }

        return Str::contains($timezone, '/');
    }
}
