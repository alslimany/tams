<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'item_details' => 'array',
            'price' => 'decimal:2',
            'taxes' => 'decimal:2',
            'total' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'net_commission' => 'decimal:2',
            'agent_commission' => 'decimal:2',
            'paid' => 'decimal:2',
            'remaining' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refundParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refund_parent_id');
    }

    public function airlineTransaction(): BelongsTo
    {
        return $this->belongsTo(AirlineTransaction::class, 'airline_transaction_id');
    }
}
