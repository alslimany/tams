<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class AccountRouting extends Model
{
    protected $table = 'account_routing';

    protected $fillable = [
        'event_type',
        'event_category',
        'debit_account',
        'credit_account',
        'description',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
