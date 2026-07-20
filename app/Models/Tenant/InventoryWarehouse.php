<?php

namespace App\Models\Tenant;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryWarehouse extends Model
{
    protected $table = 'inventory_warehouses';

    protected $fillable = [
        'name',
        'code',
        'type',
        'address',
        'manager_user_id',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function stock(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'warehouse_id');
    }
}
