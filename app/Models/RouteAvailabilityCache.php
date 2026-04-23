<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteAvailabilityCache extends Model
{
    protected $table = 'route_availability_cache';

    protected $fillable = [
        'airline_code',
        'origin',
        'destination',
        'has_flights',
        'last_seen_at',
        'last_checked_at',
        'consecutive_empty',
    ];

    protected function casts(): array
    {
        return [
            'has_flights' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'consecutive_empty' => 'integer',
        ];
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setConnection(config('tenancy.database.central_connection', config('database.default', 'sqlite')));
    }
}
