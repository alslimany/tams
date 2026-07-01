<?php

namespace App\Services\ESim;

use App\Models\Airport;
use App\Models\Country;

class AirportCountryResolver
{
    public function airportForIata(string $iata): ?Airport
    {
        $iata = strtoupper(trim($iata));

        if ($iata === '') {
            return null;
        }

        return Airport::query()->where('iata_code', $iata)->first();
    }

    public function iso2ForIata(string $iata): ?string
    {
        return $this->iso2ForAirport($this->airportForIata($iata));
    }

    public function iso2ForAirport(?Airport $airport): ?string
    {
        if ($airport === null) {
            return null;
        }

        $countryValue = $this->readCountryValue($airport);

        if ($countryValue === null) {
            return null;
        }

        if (strlen($countryValue) === 2 && ctype_alpha($countryValue)) {
            return strtoupper($countryValue);
        }

        $matched = Country::query()
            ->where(function ($query) use ($countryValue): void {
                $query->whereRaw('upper(alpha2) = ?', [$countryValue])
                    ->orWhereRaw('upper(name_en) = ?', [$countryValue])
                    ->orWhereRaw('upper(name_ar) = ?', [$countryValue])
                    ->orWhereRaw('upper(name_fr) = ?', [$countryValue]);
            })
            ->value('alpha2');

        return is_string($matched) && $matched !== ''
            ? strtoupper($matched)
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function airportContext(string $iata): ?array
    {
        $airport = $this->airportForIata($iata);

        if ($airport === null) {
            return null;
        }

        $iso2 = $this->iso2ForAirport($airport);
        $country = $iso2 !== null
            ? Country::query()->where('alpha2', strtolower($iso2))->first(['alpha2', 'name_en', 'name_ar', 'name_fr'])
            : null;

        return [
            'iata' => strtoupper($iata),
            'name' => $airport->getTranslation('name', 'en'),
            'city' => $airport->getTranslation('city', 'en'),
            'country_iso' => $iso2,
            'country' => $country ? [
                'alpha2' => strtoupper($country->alpha2),
                'name_en' => $country->name_en,
                'name_ar' => $country->name_ar,
                'name_fr' => $country->name_fr,
            ] : null,
        ];
    }

    private function readCountryValue(Airport $airport): ?string
    {
        $country = null;

        if (method_exists($airport, 'getTranslation')) {
            $country = $airport->getTranslation('country', 'en', false);
        }

        if (! $country) {
            $rawCountry = $airport->getAttributes()['country'] ?? null;

            if (is_string($rawCountry) && str_starts_with($rawCountry, '{')) {
                $decoded = json_decode($rawCountry, true);
                $country = $decoded['en'] ?? (is_array($decoded) ? reset($decoded) : null);
            } elseif (is_string($rawCountry)) {
                $country = $rawCountry;
            }
        }

        if (! is_string($country) || trim($country) === '') {
            return null;
        }

        return strtoupper(trim($country));
    }
}
