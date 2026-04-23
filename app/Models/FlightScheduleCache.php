<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlightScheduleCache extends Model
{
    protected $table = 'flight_schedule_cache';

    protected $fillable = [
        'airline_code',
        'origin',
        'destination',
        'flight_date',
        'booking_class',
        'lowest_price',
        'currency',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'flight_date' => 'date',
            'lowest_price' => 'float',
            'expires_at' => 'datetime',
        ];
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setConnection(config('tenancy.database.central_connection', config('database.default', 'sqlite')));
    }
}
