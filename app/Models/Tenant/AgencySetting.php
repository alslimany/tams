<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class AgencySetting extends Model
{
    protected $fillable = [
        'can_use_own_airline_credentials',
        'force_use_default_agency',
        'default_agency_tenant_id',
        'master_commission_percent',
    ];

    protected function casts(): array
    {
        return [
            'can_use_own_airline_credentials' => 'boolean',
            'force_use_default_agency' => 'boolean',
            'master_commission_percent' => 'decimal:2',
        ];
    }

    /**
     * Get the current agency settings, creating a default row if none exists.
     */
    public static function current(): self
    {
        return self::firstOrCreate([], [
            'can_use_own_airline_credentials' => true,
            'force_use_default_agency' => false,
            'master_commission_percent' => 0,
        ]);
    }

    /**
     * Whether this agency is forced to use the default agency supply.
     */
    public function isForcedToUseDefaultAgency(): bool
    {
        return (bool) $this->force_use_default_agency;
    }

    /**
     * Whether this agency can use its own airline credentials.
     */
    public function canUseOwnAirlineCredentials(): bool
    {
        return (bool) $this->can_use_own_airline_credentials;
    }

    /**
     * Get the master commission percent for this agency.
     * This is the commission the default agency earns on tickets sold by this agency.
     */
    public function getMasterCommissionPercent(): float
    {
        return (float) ($this->master_commission_percent ?? 0);
    }
}
