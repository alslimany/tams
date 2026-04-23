<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'refunded_at' => 'datetime',
            'raw_response' => 'array',
        ];
    }
}
