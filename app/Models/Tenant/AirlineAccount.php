<?php

namespace App\Models\Tenant;

use App\Models\TenantProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @deprecated Migration source only; use Bavix provider wallets on TenantProvider instead.
 */
class AirlineAccount extends Model
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
        return $this->belongsTo(TenantProvider::class, 'tenant_provider_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AirlineTransaction::class);
    }
}
