<?php

namespace App\Models;

use Bavix\Wallet\Interfaces\Wallet as WalletInterface;
use Bavix\Wallet\Models\Wallet;
use Bavix\Wallet\Traits\HasWallet;
use Bavix\Wallet\Traits\HasWallets;
use Illuminate\Database\Eloquent\Model;

class TenantProvider extends Model implements WalletInterface
{
    use HasWallet;
    use HasWallets;

    protected $fillable = [
        'provider_type',
        'airline_code',
        'airline_name',
        'account_name',
        'credentials',
        'is_active',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'last_used_at',
        'domestic_commission_rate',
        'international_commission_rate',
        'commission_domestic',
        'commission_international',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credentials' => 'encrypted:json',
        'last_tested_at' => 'datetime',
        'last_used_at' => 'datetime',
        'domestic_commission_rate' => 'decimal:2',
        'international_commission_rate' => 'decimal:2',
        'commission_domestic' => 'decimal:2',
        'commission_international' => 'decimal:2',
    ];

    public function getOrCreateCurrencyWallet(string $currency): Wallet
    {
        $normalizedCurrency = strtoupper($currency);
        $slug = 'AIR_'.$normalizedCurrency;

        return $this->getWallet($slug) ?? $this->createWallet([
            'name' => $this->airline_name.' '.$normalizedCurrency.' Wallet',
            'slug' => $slug,
            'meta' => [
                'currency' => $normalizedCurrency,
                'airline_code' => $this->airline_code,
            ],
        ]);
    }

    public function getBalance(string $currency): float
    {
        return (float) $this->getOrCreateCurrencyWallet($currency)->balanceFloat;
    }
}
