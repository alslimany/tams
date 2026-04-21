<?php

namespace App\Models;

use Illuminate\Support\Str;
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

    public function usesOwnAirlineCredentials(?string $airlineCode = null): bool
    {
        $settings = $this->settings ?? [];

        $global = data_get($settings, 'finance.use_own_airline_credentials');
        if (is_bool($global)) {
            return $global;
        }

        if ($airlineCode) {
            $perAirline = data_get($settings, 'finance.airlines.'.Str::upper($airlineCode).'.use_own_credentials');
            if (is_bool($perAirline)) {
                return $perAirline;
            }
        }

        return true;
    }
}
