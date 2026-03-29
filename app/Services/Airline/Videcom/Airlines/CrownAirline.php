<?php

namespace App\Services\Airline\Videcom\Airlines;

use App\Services\Airline\Videcom\BaseVidecomAirline;

class CrownAirline extends BaseVidecomAirline
{
    public function getIataCode(): string
    {
        return 'FQ';
    }

    public function getName(): string
    {
        return 'Crown Air';
    }

    public function getVidecomCode(): string
    {
        return 'FlyCrown';
    }
}
