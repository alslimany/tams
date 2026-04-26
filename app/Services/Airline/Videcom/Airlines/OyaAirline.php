<?php

namespace App\Services\Airline\Videcom\Airlines;

use App\Services\Airline\Videcom\BaseVidecomAirline;

class OyaAirline extends BaseVidecomAirline
{
    public function getIataCode(): string
    {
        return 'YI';
    }

    public function getName(): string
    {
        return 'Oya Airline';
    }

    public function getVidecomCode(): string
    {
        return 'Oya';
    }
}
