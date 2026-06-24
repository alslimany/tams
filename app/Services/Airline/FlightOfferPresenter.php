<?php

namespace App\Services\Airline;

use App\DTOs\Airline\FlightOption;
use App\Support\AirlineLogoUrl;

class FlightOfferPresenter
{
    /**
     * @param  array<string, mixed>|FlightOption  $offer
     * @return array<string, mixed>
     */
    public function present(array|FlightOption $offer): array
    {
        $data = $offer instanceof FlightOption ? $offer->toArray() : $offer;
        $pricing = is_array($data['pricing'] ?? null) ? $data['pricing'] : [];

        $data['baggage'] = [
            'hold_weight' => $pricing['hold_weight'] ?? null,
            'hand_weight' => $pricing['hand_weight'] ?? null,
            'hold_pieces' => $pricing['hold_pieces'] ?? null,
        ];

        $data['airline_logo_url'] = AirlineLogoUrl::forCode((string) ($data['airline_code'] ?? ''));

        if (! empty($pricing['fare_id'])) {
            $data['fare_id'] = $pricing['fare_id'];
        }

        return $data;
    }

    /**
     * @param  iterable<int, array<string, mixed>|FlightOption>  $offers
     * @return list<array<string, mixed>>
     */
    public function presentMany(iterable $offers): array
    {
        $presented = [];

        foreach ($offers as $offer) {
            $presented[] = $this->present($offer);
        }

        return $presented;
    }
}
