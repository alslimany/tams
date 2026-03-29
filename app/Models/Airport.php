<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Airport extends Model
{
    use HasTranslations;

    public $translatable = ['name', 'city', 'country'];

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('tenancy.database.central_connection', 'sqlite'));
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}
