<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantProvider extends Model
{
    protected $fillable = [
        'provider_type',
        'airline_code',
        'airline_name',
        'account_name',
        'credentials',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credentials' => 'encrypted:json',
    ];
}
