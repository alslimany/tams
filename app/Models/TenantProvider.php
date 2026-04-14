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
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credentials' => 'encrypted:json',
        'last_tested_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];
}
