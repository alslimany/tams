<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RouteInternationalService
{
    public function isInternational(string $origin, string $destination): bool
    {
        $originCountry = $this->resolveCountryCode($origin);
        $destinationCountry = $this->resolveCountryCode($destination);

        if ($originCountry === null || $destinationCountry === null) {
            return false;
        }

        return $originCountry !== $destinationCountry;
    }

    protected function resolveCountryCode(string $airport): ?string
    {
        $normalizedAirport = strtoupper(trim($airport));

        if ($normalizedAirport === '') {
            return null;
        }

        $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));
        $cacheKey = sprintf('finance:airport_country:%s', $normalizedAirport);

        return Cache::remember($cacheKey, now()->addDay(), function () use ($connection, $normalizedAirport): ?string {
            $query = DB::connection($connection)->table('airport_countries');

            if (Schema::connection($connection)->hasColumn('airport_countries', 'is_active')) {
                $query->where('is_active', true);
            }

            if (Schema::connection($connection)->hasColumn('airport_countries', 'airport_code')) {
                $countryCode = $query->where('airport_code', $normalizedAirport)->value('country_code');

                if ($countryCode !== null) {
                    return strtoupper((string) $countryCode);
                }

                $query = DB::connection($connection)->table('airport_countries');

                if (Schema::connection($connection)->hasColumn('airport_countries', 'is_active')) {
                    $query->where('is_active', true);
                }
            }

            $countryCode = $query->where('country_code', $normalizedAirport)->value('country_code');

            return $countryCode !== null ? strtoupper((string) $countryCode) : null;
        });
    }
}
