<?php

namespace App\Support;

class Iso8601Duration
{
    /**
     * Convert minutes to an ISO 8601 duration (e.g. PT2H10M, PT13H20M).
     */
    public static function fromMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'PT0M';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        $duration = 'PT';

        if ($hours > 0) {
            $duration .= "{$hours}H";
        }

        if ($remainingMinutes > 0) {
            $duration .= "{$remainingMinutes}M";
        }

        return $duration;
    }
}
