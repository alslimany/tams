<?php

namespace App\Services\Airline\Videcom\Airlines;

use App\Services\Airline\Videcom\BaseVidecomAirline;

class GlobalAirline extends BaseVidecomAirline
{
    public function getIataCode(): string
    {
        return '5S';
    }

    public function getName(): string
    {
        return 'Global Air';
    }

    public function getVidecomCode(): string
    {
        return 'GlobalAir';
    }
}
