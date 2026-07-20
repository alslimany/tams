<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class CoaSetting extends Model
{
    protected $table = 'coa_settings';

    protected $fillable = [
        'ledger_uuid',
        'code',
        'display_name',
        'account_type',
        'parent_code',
        'is_system',
        'is_active',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
