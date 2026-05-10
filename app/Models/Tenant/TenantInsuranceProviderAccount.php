<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @deprecated Migration source only; use Bavix provider wallets on TenantInsuranceProvider instead.
 */
class TenantInsuranceProviderAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(TenantInsuranceProvider::class, 'tenant_insurance_provider_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(TenantInsuranceProviderTransaction::class, 'tenant_insurance_provider_account_id');
    }
}
