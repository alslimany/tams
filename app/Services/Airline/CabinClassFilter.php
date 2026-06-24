<?php

namespace App\Services\Airline;

use App\DTOs\Airline\FlightOption;

class CabinClassFilter
{
    /**
     * Normalize cabin class input to a Videcom cabin code (Y, C, F) or null for "all".
     */
    public static function normalize(?string $cabinClass): ?string
    {
        if ($cabinClass === null || $cabinClass === '' || strtolower($cabinClass) === 'all') {
            return null;
        }

        return match (strtolower($cabinClass)) {
            'economy', 'y' => 'Y',
            'premium_economy' => 'W',
            'business', 'c' => 'C',
            'first', 'f' => 'F',
            default => strtoupper($cabinClass),
        };
    }

    /**
     * @param  array<string, mixed>|FlightOption  $offer
     */
    public static function matches(array|FlightOption $offer, ?string $normalizedCabin): bool
    {
        if ($normalizedCabin === null) {
            return true;
        }

        $data = $offer instanceof FlightOption ? $offer->toArray() : $offer;
        $offerCabin = self::extractCabinCode($data);

        if ($offerCabin === '') {
            return true;
        }

        if ($normalizedCabin === 'W') {
            return in_array($offerCabin, ['W', 'P', 'S'], true);
        }

        return $offerCabin === $normalizedCabin
            || str_starts_with($offerCabin, $normalizedCabin);
    }

    /**
     * @param  iterable<int, array<string, mixed>|FlightOption>  $offers
     * @return list<array<string, mixed>|FlightOption>
     */
    public static function filter(iterable $offers, ?string $cabinClass): array
    {
        $normalized = self::normalize($cabinClass);

        return array_values(array_filter(
            is_array($offers) ? $offers : iterator_to_array($offers),
            fn (array|FlightOption $offer): bool => self::matches($offer, $normalized),
        ));
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    protected static function extractCabinCode(array $offer): string
    {
        $pricing = $offer['pricing'] ?? [];
        $segments = $offer['segments'] ?? [];

        $cabin = (string) (
            $pricing['cabin_type']
            ?? ($segments[0]['cabin_type'] ?? null)
            ?? ($segments[0]['class'] ?? null)
            ?? $pricing['class_code']
            ?? ''
        );

        return strtoupper(trim($cabin));
    }
}
