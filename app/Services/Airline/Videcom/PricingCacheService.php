<?php

namespace App\Services\Airline\Videcom;

use Illuminate\Support\Facades\Cache;

class PricingCacheService
{
    /**
     * Get a cached price or null if not found.
     */
    public static function get(string $airline, string $origin, string $dest, string $class, string $paxType): mixed
    {
        $key = self::buildKey($airline, $origin, $dest, $class, $paxType);

        return Cache::get($key);
    }

    /**
     * Store a price in cache.
     *
     * Successful prices are cached for 1 hour so repeat searches within the same
     * session don't re-probe the VRS.  Failed probes (sentinel false) are cached
     * for only 5 minutes so a transient network or session error doesn't block an
     * entire hour of searches.
     */
    public static function put(string $airline, string $origin, string $dest, string $class, string $paxType, mixed $data): void
    {
        $key = self::buildKey($airline, $origin, $dest, $class, $paxType);
        $ttl = $data === false ? now()->addMinutes(5) : now()->addHour();
        Cache::put($key, $data, $ttl);
    }

    /**
     * Get cached baggage allowance for a route/class or null if not found.
     *
     * @return array{hold_weight: string, hand_weight: string, hold_pieces: string}|null
     */
    public static function getBaggage(string $airline, string $origin, string $dest, string $class): mixed
    {
        return Cache::get(self::buildBaggageKey($airline, $origin, $dest, $class));
    }

    /**
     * Store baggage allowance in cache for 1 hour.
     *
     * @param  array{hold_weight: string, hand_weight: string, hold_pieces: string}  $data
     */
    public static function putBaggage(string $airline, string $origin, string $dest, string $class, array $data): void
    {
        Cache::put(self::buildBaggageKey($airline, $origin, $dest, $class), $data, now()->addHour());
    }

    /**
     * Build a unique cache key for the pricing.
     */
    protected static function buildKey(string $airline, string $origin, string $dest, string $class, string $paxType): string
    {
        return "vrs_price:{$airline}:{$origin}:{$dest}:{$class}:{$paxType}";
    }

    /**
     * Build a unique cache key for baggage allowance (not pax-type specific).
     */
    protected static function buildBaggageKey(string $airline, string $origin, string $dest, string $class): string
    {
        return "vrs_baggage:{$airline}:{$origin}:{$dest}:{$class}";
    }
}
