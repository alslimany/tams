<?php

namespace App\DTOs\Airline;

class RoundTripPriceResult
{
    public function __construct(
        public float $returnLegPrice,
        public string $currency,
        public float $totalPrice,
        public array $taxes = [],
        /** @var array<int, array{hold_weight: string, hand_weight: string, hold_pieces: string}> keyed by segment number (1 = outbound, 2 = return) */
        public array $segmentBaggage = [],
    ) {}
}
