<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class TenantInsuranceProvider extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'credentials' => 'array',
            'is_active' => 'boolean',
            'commission_compulsory' => 'decimal:2',
            'commission_travel' => 'decimal:2',
            'commission_orange' => 'decimal:2',
        ];
    }

    public function commissionForProductType(string $productType): float
    {
        return match (strtolower($productType)) {
            'compulsory' => (float) $this->commission_compulsory,
            'travel' => (float) $this->commission_travel,
            'orange' => (float) $this->commission_orange,
            default => 0.0,
        };
    }
}
