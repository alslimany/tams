<?php

namespace App\DTOs\Airline;

class RoundTripPriceRequest
{
    /**
     * @param  array<string, mixed>  $outboundSegment
     * @param  array<string, mixed>  $returnSegment
     * @param  array<string, int>  $passengers
     */
    public function __construct(
        public array $outboundSegment,
        public array $returnSegment,
        public array $passengers,
        public ?float $outboundPrice = null,
    ) {}
}
