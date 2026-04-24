<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AirportCountry extends Model
{
    protected $fillable = [
        'country_code',
        'country_name',
        'iso3_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
