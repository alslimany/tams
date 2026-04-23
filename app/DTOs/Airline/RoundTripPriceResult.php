<?php

namespace App\DTOs\Airline;

class RoundTripPriceResult
{
    public function __construct(
        public float $returnLegPrice,
        public string $currency,
        public float $totalPrice,
        public array $taxes = [],
    ) {}
}
