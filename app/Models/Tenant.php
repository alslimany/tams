<?php

namespace App\Models;

use Bavix\Wallet\Interfaces\Wallet as WalletInterface;
use Bavix\Wallet\Traits\HasWalletFloat;
use Bavix\Wallet\Traits\HasWallets;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase, WalletInterface
{
    use HasDatabase, HasDomains;
    use HasWalletFloat, HasWallets;

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'direct',
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'path',
            'type',
            'agency_number',
            'office_id',
            'city_iata',
            'company_name',
            'owner_name',
            'owner_email',
            'owner_phone',
            'commercial_register_path',
            'passport_path',
            'status',
            'subscription_status',
            'subscription_plan',
            'settings',
            'last_activity_at',
            'is_default_agency',
            'master_commission_rate',
        ];
    }

    protected function casts(): array
    {
        return [
            'type' => 'string',
            'settings' => 'array',
            'last_activity_at' => 'datetime',
            'is_default_agency' => 'boolean',
            'master_commission_rate' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $tenant->agency_number ??= static::nextAgencyNumber();
        });
    }

    public static function nextAgencyNumber(): string
    {
        $latest = static::query()
            ->whereNotNull('agency_number')
            ->where('agency_number', 'like', 'AG-%')
            ->orderByDesc(DB::raw('CAST(SUBSTR(agency_number, 4) AS INTEGER)'))
            ->value('agency_number');

        $sequence = is_string($latest) ? ((int) Str::after($latest, 'AG-')) + 1 : 100001;

        return sprintf('AG-%06d', $sequence);
    }

    public function isDefaultAgency(): bool
    {
        return (bool) $this->is_default_agency;
    }

    public function getMasterCommissionRate(): float
    {
        return (float) ($this->master_commission_rate ?? 0);
    }

    public static function getDefaultAgency(): ?self
    {
        return static::query()
            ->where('is_default_agency', true)
            ->where('status', 'active')
            ->first();
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(AgencyWalletTransaction::class, 'tenant_id');
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
