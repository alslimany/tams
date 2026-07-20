<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    protected $table = 'inventory_items';

    protected $fillable = [
        'code',
        'name',
        'category',
        'unit',
        'unit_cost',
        'inventory_account',
        'cogs_account',
        'purchase_account',
        'track_quantity',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:3',
            'track_quantity' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function stock(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'item_id');
    }
}
