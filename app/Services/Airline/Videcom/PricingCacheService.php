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
     * Store a price in cache for 1 hour.
     */
    public static function put(string $airline, string $origin, string $dest, string $class, string $paxType, mixed $data): void
    {
        $key = self::buildKey($airline, $origin, $dest, $class, $paxType);
        Cache::put($key, $data, now()->addHour());
    }

    /**
     * Build a unique cache key for the pricing.
     */
    protected static function buildKey(string $airline, string $origin, string $dest, string $class, string $paxType): string
    {
        return "vrs_price:{$airline}:{$origin}:{$dest}:{$class}:{$paxType}";
    }
}
