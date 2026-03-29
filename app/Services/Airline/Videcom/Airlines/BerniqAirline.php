<?php

namespace App\Services\Airline\Videcom\Airlines;

use App\Services\Airline\Videcom\BaseVidecomAirline;

class BerniqAirline extends BaseVidecomAirline
{
    public function getIataCode(): string
    {
        return 'NB';
    }

    public function getName(): string
    {
        return 'Berniq Air';
    }

    public function getVidecomCode(): string
    {
        return 'Berniq';
    }
}
