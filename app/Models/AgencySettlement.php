<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class AgencySettlement extends Model
{
    use CentralConnection;

    protected $fillable = [
        'buyer_tenant_id',
        'default_agency_tenant_id',
        'currency',
        'total_commission',
        'transaction_count',
        'period_started_at',
        'period_ended_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'total_commission' => 'decimal:2',
            'transaction_count' => 'integer',
            'period_started_at' => 'datetime',
            'period_ended_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'buyer_tenant_id');
    }

    public function defaultAgency(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'default_agency_tenant_id');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(AgencyWalletTransaction::class, 'settlement_id');
    }
}
