<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
            $row = DB::connection($connection)
                ->table('airports')
                ->where('iata_code', $normalizedAirport)
                ->value('country');

            if ($row === null) {
                return null;
            }

            $countryData = json_decode((string) $row, true);

            if (! is_array($countryData)) {
                return null;
            }

            // Prefer the 'en' key; it is usually a 2-letter ISO code.
            // Some records store a full country name in 'en' (e.g. "Libya") — in that
            // case fall back to 'fr' which consistently holds the ISO code.
            foreach (['en', 'fr'] as $lang) {
                $value = strtoupper(trim((string) ($countryData[$lang] ?? '')));
                if ($value !== '' && strlen($value) <= 3) {
                    return $value;
                }
            }

            return null;
        });
    }
}
