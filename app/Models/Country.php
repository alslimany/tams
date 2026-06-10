<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Country extends Model
{
    use CentralConnection;

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'alpha2',
        'alpha3',
        'name_en',
        'name_ar',
        'name_fr',
        'esim_featured',
    ];

    protected function casts(): array
    {
        return [
            'esim_featured' => 'boolean',
        ];
    }
}
