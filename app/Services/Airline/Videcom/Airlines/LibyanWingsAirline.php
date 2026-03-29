<?php

namespace App\Services\Airline\Videcom\Airlines;

use App\Services\Airline\Videcom\BaseVidecomAirline;

class LibyanWingsAirline extends BaseVidecomAirline
{
    public function getIataCode(): string
    {
        return 'YL';
    }

    public function getName(): string
    {
        return 'Libyan Wings';
    }

    public function getVidecomCode(): string
    {
        return 'LibyanWings';
    }
}
