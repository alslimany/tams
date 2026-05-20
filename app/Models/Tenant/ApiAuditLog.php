<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ApiAuditLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
        ];
    }
}
