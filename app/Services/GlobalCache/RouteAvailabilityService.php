<?php

namespace App\Services\GlobalCache;

use App\Models\RouteAvailabilityCache;
use Carbon\Carbon;

class RouteAvailabilityService
{
    public function hasFlights(string $airlineCode, string $origin, string $destination): ?bool
    {
        $route = RouteAvailabilityCache::query()
            ->where('airline_code', strtoupper(trim($airlineCode)))
            ->where('origin', strtoupper(trim($origin)))
            ->where('destination', strtoupper(trim($destination)))
            ->first();

        if (! $route) {
            return null;
        }

        if ((int) $route->consecutive_empty >= 3) {
            return false;
        }

        if ((int) $route->consecutive_empty > 0) {
            return null;
        }

        if ($route->has_flights && $route->last_seen_at && $route->last_seen_at->greaterThanOrEqualTo(now()->subDays(7))) {
            return true;
        }

        $lastTouch = $route->last_checked_at ?? $route->last_seen_at;
        if (! $lastTouch || $lastTouch->lt(now()->subDays(7))) {
            return null;
        }

        return $route->has_flights ? true : null;
    }

    public function recordResult(string $airlineCode, string $origin, string $destination, bool $foundAnyFlights): RouteAvailabilityCache
    {
        $now = Carbon::now();

        $route = RouteAvailabilityCache::query()->firstOrNew([
            'airline_code' => strtoupper(trim($airlineCode)),
            'origin' => strtoupper(trim($origin)),
            'destination' => strtoupper(trim($destination)),
        ]);

        $route->last_checked_at = $now;

        if ($foundAnyFlights) {
            $route->has_flights = true;
            $route->last_seen_at = $now;
            $route->consecutive_empty = 0;
            $route->save();

            return $route;
        }

        $route->consecutive_empty = max(0, (int) $route->consecutive_empty) + 1;

        if ((int) $route->consecutive_empty >= 3) {
            $route->has_flights = false;
        }

        $route->save();

        return $route;
    }
}
