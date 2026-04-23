<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandlordSetting extends Model
{
    protected $table = 'landlord_settings';

    protected $fillable = [
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setConnection(config('tenancy.database.central_connection', config('database.default', 'sqlite')));
    }
}
