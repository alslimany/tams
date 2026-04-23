<?php

namespace App\Services\Airline\Videcom;

use App\DTOs\Airline\FlightOption;
use SimpleXMLElement;

class VidecomResponseParser
{
    /**
     * Parse availability XML into an array of FlightOption DTOs.
     *
     * @return array<FlightOption>
     */
    public static function parseAvailability(SimpleXMLElement $xml, string $airlineCode, string $airlineName): array
    {
        $options = [];

        if (! isset($xml->itin)) {
            return [];
        }

        // Map class bands for easy lookup
        $bands = [];
        if (isset($xml->classbands->band)) {
            foreach ($xml->classbands->band as $band) {
                $cb = (string) $band['cb'];
                $bands[$cb] = [
                    'name' => (string) $band['cbdisplayname'],
                    'cabin' => (string) $band['cabin'],
                    'details' => (string) $band['cbname'],
                ];
            }
        }

        foreach ($xml->itin as $itin) {
            foreach ($itin->flt as $flt) {
                $departureTime = (string) $flt->time->ddaylcl.' '.(string) $flt->time->dtimlcl;
                $arrivalTime = (string) $flt->time->adaylcl.' '.(string) $flt->time->atimlcl;
                $duration = (int) ($flt->time->duration ?? 0);

                // Videcom groups fltno and eqp under fltdet
                $fltno = (string) ($flt->fltdet->fltno ?? $flt->fltno ?? '');
                $eqp = (string) ($flt->fltdet->eqp ?? $flt->eqp ?? '');

                // Extract all valid branded offers from fltav
                if (isset($flt->fltav)) {
                    $avs = $flt->fltav->av ?? [];
                    $prices = $flt->fltav->pri ?? [];
                    $taxes = $flt->fltav->tax ?? [];
                    $curs = $flt->fltav->cur ?? [];
                    $cbs = $flt->fltav->cb ?? [];
                    $ids = $flt->fltav->id ?? [];

                    $count = count($avs);
                    for ($i = 0; $i < $count; $i++) {
                        $seatsAvailable = (int) $avs[$i];
                        $basePrice = (float) ($prices[$i] ?? 0);
                        $taxAmount = (float) ($taxes[$i] ?? 0);
                        $classTotal = $basePrice + $taxAmount;

                        $cbId = (string) ($cbs[$i] ?? '');
                        $classCode = (string) ($ids[$i] ?? '');
                        $bandInfo = $bands[$cbId] ?? ['name' => "Class $classCode", 'details' => ''];

                        $pricing = [
                            'currency' => (string) ($curs[$i] ?? 'LYD'),
                            'total' => $classTotal,
                            'breakdown' => $classTotal > 0
                                ? [
                                    ['label' => 'Base Fare', 'amount' => $basePrice],
                                    ['label' => 'Taxes & Fees', 'amount' => $taxAmount],
                                ]
                                : [],
                            'brand_name' => $bandInfo['name'],
                            'brand_details' => $bandInfo['details'],
                            'class_code' => $classCode,
                            'cabin_type' => $bandInfo['cabin'] ?? 'Y',
                        ];

                        $options[] = new FlightOption(
                            id: $fltno.'-'.(string) $flt->dep.'-'.(string) $flt->arr.'-'.(string) $flt->time->ddaylcl.'-'.$classCode,
                            airline_code: $airlineCode,
                            airline_name: $airlineName.' ('.$bandInfo['name'].')',
                            flight_number: $fltno,
                            departure_airport: (string) $flt->dep,
                            arrival_airport: (string) $flt->arr,
                            departure_time: $departureTime,
                            arrival_time: $arrivalTime,
                            segments: [
                                [
                                    'flight_number' => $fltno,
                                    'departure_airport' => (string) $flt->dep,
                                    'arrival_airport' => (string) $flt->arr,
                                    'departure_time' => $departureTime,
                                    'arrival_time' => $arrivalTime,
                                    'aircraft' => $eqp,
                                    'class' => $classCode,
                                    'cabin_type' => $bandInfo['cabin'] ?? 'Y',
                                    'duration' => $duration,
                                ],
                            ],
                            pricing: $pricing,
                            available_seats: $seatsAvailable,
                            raw_data: (array) $flt
                        );
                    }
                }
            }
        }

        return $options;
    }
}
