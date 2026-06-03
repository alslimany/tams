<?php

namespace App\Services\Airline\Videcom;

use App\DTOs\Airline\FlightOption;
use Carbon\Carbon;
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

    /**
     * Parse the ~X availability XML format (AvailabilityResponse) into FlightOption DTOs.
     *
     * The ~X command returns a simpler XML structure:
     *   <AvailabilityResponse>
     *     <Journeys>
     *       <Journey>
     *         <Legs>
     *           <BookFlightSegmentType>
     *             <Availability>
     *               <Class id="Y" av="35" cab="Y" />
     *             </Availability>
     *           </BookFlightSegmentType>
     *         </Legs>
     *       </Journey>
     *     </Journeys>
     *   </AvailabilityResponse>
     *
     * @return array<FlightOption>
     */
    public static function parseXAvailability(SimpleXMLElement $xml, string $airlineCode, string $airlineName, string $currency = 'LYD'): array
    {
        $options = [];

        if (! isset($xml->Journeys->Journey)) {
            return [];
        }

        foreach ($xml->Journeys->Journey as $journey) {
            if (! isset($journey->Legs->BookFlightSegmentType)) {
                continue;
            }

            foreach ($journey->Legs->BookFlightSegmentType as $segment) {
                $fltno = (string) ($segment->FlightNumber ?? '');
                $dep = (string) ($segment->DepartureAirport['LocationCode'] ?? '');
                $arr = (string) ($segment->ArrivalAirport['LocationCode'] ?? '');
                $departureTime = str_replace('T', ' ', (string) ($segment->XSDDepartureDateTime ?? $segment['DepartureDateTime'] ?? ''));
                $arrivalTime = str_replace('T', ' ', (string) ($segment->XSDArrivalDateTime ?? $segment->ArrivalDateTime ?? ''));
                $aircraft = (string) ($segment->Equipment['AirEquipType'] ?? '');

                // Compute duration in minutes from ISO timestamps
                $duration = 0;

                try {
                    $depCarbon = Carbon::parse($departureTime);
                    $arrCarbon = Carbon::parse($arrivalTime);
                    $duration = (int) $depCarbon->diffInMinutes($arrCarbon);
                } catch (\Throwable) {
                    // Leave duration as 0 if parsing fails
                }

                if (! isset($segment->Availability->Class)) {
                    continue;
                }

                foreach ($segment->Availability->Class as $class) {
                    $classCode = strtoupper((string) ($class['id'] ?? ''));
                    $seatsAvailable = (int) ($class['av'] ?? 0);
                    $cabinType = strtoupper((string) ($class['cab'] ?? 'Y'));

                    if ($classCode === '') {
                        continue;
                    }

                    $date = substr($departureTime, 0, 10);

                    $options[] = new FlightOption(
                        id: $fltno.'-'.$dep.'-'.$arr.'-'.$date.'-'.$classCode,
                        airline_code: $airlineCode,
                        airline_name: $airlineName.' (Class '.$classCode.')',
                        flight_number: $fltno,
                        departure_airport: $dep,
                        arrival_airport: $arr,
                        departure_time: $departureTime,
                        arrival_time: $arrivalTime,
                        segments: [
                            [
                                'flight_number' => $fltno,
                                'departure_airport' => $dep,
                                'arrival_airport' => $arr,
                                'departure_time' => $departureTime,
                                'arrival_time' => $arrivalTime,
                                'aircraft' => $aircraft,
                                'class' => $classCode,
                                'cabin_type' => $cabinType,
                                'duration' => $duration,
                            ],
                        ],
                        pricing: [
                            'currency' => $currency,
                            'total' => 0,
                            'breakdown' => [],
                            'brand_name' => 'Class '.$classCode,
                            'brand_details' => '',
                            'class_code' => $classCode,
                            'cabin_type' => $cabinType,
                        ],
                        available_seats: $seatsAvailable,
                        raw_data: []
                    );
                }
            }
        }

        return $options;
    }
}
