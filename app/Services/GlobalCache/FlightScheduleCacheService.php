<?php

namespace App\Services\GlobalCache;

use App\Models\FlightScheduleCache;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class FlightScheduleCacheService
{
    public function getLowestPrice(string $origin, string $destination, string|CarbonInterface $date): ?array
    {
        $flightDate = Carbon::parse($date)->toDateString();

        $entry = FlightScheduleCache::query()
            ->where('origin', strtoupper(trim($origin)))
            ->where('destination', strtoupper(trim($destination)))
            ->whereDate('flight_date', $flightDate)
            ->where('expires_at', '>', now())
            ->orderBy('lowest_price')
            ->first();

        if (! $entry) {
            return null;
        }

        return [
            'price' => (float) $entry->lowest_price,
            'currency' => (string) $entry->currency,
            'airline' => (string) $entry->airline_code,
            'booking_class' => $entry->booking_class,
        ];
    }

    public function storePrice(
        string $airlineCode,
        string $origin,
        string $destination,
        string|CarbonInterface $date,
        ?string $bookingClass,
        float $price,
        string $currency,
        int $ttlHours = 24,
    ): FlightScheduleCache {
        $flightDate = Carbon::parse($date)->toDateString();
        $normalizedClass = $bookingClass !== null && trim($bookingClass) !== ''
            ? strtoupper(trim($bookingClass))
            : null;

        $query = FlightScheduleCache::query()
            ->where('airline_code', strtoupper(trim($airlineCode)))
            ->where('origin', strtoupper(trim($origin)))
            ->where('destination', strtoupper(trim($destination)))
            ->whereDate('flight_date', $flightDate);

        if ($normalizedClass === null) {
            $query->whereNull('booking_class');
        } else {
            $query->where('booking_class', $normalizedClass);
        }

        $entry = $query->first();

        if ($entry) {
            $entry->lowest_price = min((float) $entry->lowest_price, $price);
            $entry->currency = strtoupper(trim($currency));
            $entry->expires_at = now()->addHours($ttlHours);
            $entry->save();

            return $entry;
        }

        return FlightScheduleCache::query()->create([
            'airline_code' => strtoupper(trim($airlineCode)),
            'origin' => strtoupper(trim($origin)),
            'destination' => strtoupper(trim($destination)),
            'flight_date' => $flightDate,
            'booking_class' => $normalizedClass,
            'lowest_price' => $price,
            'currency' => strtoupper(trim($currency)),
            'expires_at' => now()->addHours($ttlHours),
        ]);
    }
}
