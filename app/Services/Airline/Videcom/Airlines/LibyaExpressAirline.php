<?php

namespace App\Services\Airline\Videcom\Airlines;

use App\Services\Airline\Videcom\BaseVidecomAirline;

class LibyaExpressAirline extends BaseVidecomAirline
{
    public function getIataCode(): string
    {
        return 'LB';
    }

    public function getName(): string
    {
        return 'Libyan Express';
    }

    public function getVidecomCode(): string
    {
        return 'LibyanExpress';
    }
}
