<?php

namespace App\Services\Airline\Videcom;

use Closure;
use Illuminate\Support\Facades\Cache;

class OpenReservationCacheService
{
    public static function remember(string $airline, string $origin, string $destination, string $class, Closure $resolver): bool
    {
        $key = self::buildKey($airline, $origin, $destination, $class);

        return (bool) Cache::remember($key, now()->addWeek(), $resolver);
    }

    protected static function buildKey(string $airline, string $origin, string $destination, string $class): string
    {
        return sprintf(
            'videcom_open_reservation:%s:%s:%s:%s',
            strtoupper($airline),
            strtoupper($origin),
            strtoupper($destination),
            strtoupper(substr($class, 0, 1))
        );
    }
}
