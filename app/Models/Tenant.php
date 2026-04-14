<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'company_name',
            'owner_name',
            'owner_email',
            'owner_phone',
            'status',
            'subscription_status',
            'subscription_plan',
            'settings',
            'last_activity_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_activity_at' => 'datetime',
        ];
    }
}
