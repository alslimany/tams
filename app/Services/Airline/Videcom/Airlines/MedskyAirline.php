<?php

namespace App\Services\Airline\Videcom\Airlines;

use App\Services\Airline\Videcom\BaseVidecomAirline;

class MedskyAirline extends BaseVidecomAirline
{
    public function getIataCode(): string
    {
        return 'BM';
    }

    public function getName(): string
    {
        return 'Medsky Airline';
    }

    public function getVidecomCode(): string
    {
        return 'Medsky';
    }

    /**
     * Medsky has specific logic for currency/airport accounts.
     * The base class isRouteAllowed already handles the airport restrictions
     * based on the $config passed to the constructor.
     */
}
