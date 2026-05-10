<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated Migration source only; use Bavix provider wallet transactions instead.
 */
class TenantInsuranceProviderTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TenantInsuranceProviderAccount::class, 'tenant_insurance_provider_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
