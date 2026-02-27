<?php

namespace App\Services\Airline\Videcom;

use App\DTOs\Airline\FlightOption;
use SimpleXMLElement;

class VidecomResponseParser
{
    /**
     * Parse availability XML into an array of FlightOption DTOs.
     *
     * @param SimpleXMLElement $xml
     * @param string $airlineCode
     * @param string $airlineName
     * @return array<FlightOption>
     */
    public static function parseAvailability(SimpleXMLElement $xml, string $airlineCode, string $airlineName): array
    {
        $options = [];

        if (!isset($xml->itin)) {
            return [];
        }

        foreach ($xml->itin as $itin) {
            foreach ($itin->flt as $flt) {
                $departureTime = (string) $flt->time->ddaylcl . ' ' . (string) $flt->time->dtimlcl;
                $arrivalTime = (string) $flt->time->adaylcl . ' ' . (string) $flt->time->atimlcl;
                
                // Get pricing from classbands and fltav
                $pricing = self::extractPricing($xml, $flt);
                
                $options[] = new FlightOption(
                    id: (string) $flt->fltno . '-' . (string) $flt->dep . '-' . (string) $flt->arr . '-' . (string) $flt->time->ddaylcl,
                    airline_code: $airlineCode,
                    airline_name: $airlineName,
                    flight_number: (string) $flt->fltno,
                    departure_airport: (string) $flt->dep,
                    arrival_airport: (string) $flt->arr,
                    departure_time: $departureTime,
                    arrival_time: $arrivalTime,
                    segments: [
                        [
                            'flight_number' => (string) $flt->fltno,
                            'departure_airport' => (string) $flt->dep,
                            'arrival_airport' => (string) $flt->arr,
                            'departure_time' => $departureTime,
                            'arrival_time' => $arrivalTime,
                            'aircraft' => (string) $flt->eqp,
                        ]
                    ],
                    pricing: $pricing,
                    available_seats: self::getMinSeats($flt),
                    raw_data: (array) $flt
                );
            }
        }

        return $options;
    }

    /**
     * Extract pricing info from XML.
     */
    protected static function extractPricing(SimpleXMLElement $xml, SimpleXMLElement $flt): array
    {
        // Videcom availability with FGNoAv=True returns classbands and fltav
        // This is a simplified extraction logic
        $total = 0;
        $currency = 'LYD'; // Default, should be parsed from XML if available

        if (isset($flt->fltav[0])) {
            $total = (float) $flt->fltav[0]->total; // Use first available class for now
        }

        return [
            'currency' => $currency,
            'total' => $total,
            'breakdown' => []
        ];
    }

    /**
     * Get minimum seats available across classes.
     */
    protected static function getMinSeats(SimpleXMLElement $flt): int
    {
        $maxSeats = 0;
        if (isset($flt->fltav)) {
            foreach ($flt->fltav as $av) {
                $maxSeats = max($maxSeats, (int) $av->seats);
            }
        }
        return $maxSeats;
    }
}
