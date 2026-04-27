<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class DefaultAgencySetting extends Model
{
    use CentralConnection;

    protected $fillable = [
        'default_agency_tenant_id',
        'master_commission_percent',
        'allowed_airline_codes',
    ];

    protected function casts(): array
    {
        return [
            'master_commission_percent' => 'decimal:2',
            'allowed_airline_codes' => 'array',
        ];
    }

    public function defaultAgency(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'default_agency_tenant_id');
    }

    /**
     * Get the settings for the current default agency, creating a row if none exists.
     */
    public static function forDefaultAgency(string $tenantId): self
    {
        return self::firstOrCreate(
            ['default_agency_tenant_id' => $tenantId],
            ['master_commission_percent' => 0],
        );
    }

    /**
     * Check if an airline code is allowed for the default agency supply.
     * Returns true if no restrictions are set (null/empty allowed_airline_codes).
     */
    public function isAirlineAllowed(string $airlineCode): bool
    {
        if ($this->allowed_airline_codes === null || empty($this->allowed_airline_codes)) {
            return true;
        }

        return in_array(strtoupper($airlineCode), array_map('strtoupper', $this->allowed_airline_codes));
    }
}
