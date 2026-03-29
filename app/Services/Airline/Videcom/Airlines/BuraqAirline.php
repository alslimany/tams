<?php

namespace App\Services\Airline\Videcom\Airlines;

use App\Services\Airline\Videcom\BaseVidecomAirline;

class BuraqAirline extends BaseVidecomAirline
{
    public function getIataCode(): string
    {
        return 'UZ';
    }

    public function getName(): string
    {
        return 'Buraq Air';
    }

    public function getVidecomCode(): string
    {
        return 'Buraq';
    }
}
