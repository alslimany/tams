<?php

namespace App\Models\Tenant;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    protected $table = 'inventory_movements';

    protected $fillable = [
        'type',
        'reference',
        'item_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'supplier',
        'order_id',
        'notes',
        'ledger_entry_id',
        'status',
        'created_by',
        'movement_date',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:3',
            'total_cost' => 'decimal:3',
            'ledger_entry_id' => 'integer',
            'movement_date' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'to_warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
