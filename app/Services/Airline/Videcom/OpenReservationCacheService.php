<?php

namespace App\Services\Airline\Videcom;

use Closure;
use Illuminate\Support\Facades\Cache;

class OpenReservationCacheService
{
    public static function remember(string $airline, string $origin, string $destination, string $class, Closure $resolver): bool
    {
        $key = self::buildKey($airline, $origin, $destination, $class);

        $cached = Cache::get($key);

        if (is_bool($cached)) {
            return $cached;
        }

        $result = $resolver();

        // A null result means the probe could not reach a verdict (timeout,
        // transport error); fail closed without pinning that for a week.
        if (! is_bool($result)) {
            return false;
        }

        Cache::put($key, $result, now()->addWeek());

        return $result;
    }

    protected static function buildKey(string $airline, string $origin, string $destination, string $class): string
    {
        return sprintf(
            // v2: the probe switched from NN to QQ, so verdicts cached by the
            // old probe (which allowed BM Y/S) must not be reused.
            'videcom_open_reservation:v2:%s:%s:%s:%s',
            strtoupper($airline),
            strtoupper($origin),
            strtoupper($destination),
            strtoupper(substr($class, 0, 1))
        );
    }
}
