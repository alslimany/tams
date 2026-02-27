<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class TenantProvider extends Model
{
    protected $fillable = [
        'provider_type',
        'airline_code',
        'airline_name',
        'account_name',
        'credentials',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get decrypted credentials.
     *
     * @return array
     */
    public function getCredentialsAttribute($value)
    {
        try {
            return json_decode(Crypt::decrypt($value), true) ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Set encrypted credentials.
     *
     * @param array $value
     */
    public function setCredentialsAttribute($value)
    {
        $this->attributes['credentials'] = Crypt::encrypt(json_encode($value));
    }
}
