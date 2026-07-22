<?php

namespace App\Models\Tenant;

use Bavix\Wallet\Interfaces\Wallet as WalletInterface;
use Bavix\Wallet\Models\Wallet;
use Bavix\Wallet\Traits\HasWallet;
use Bavix\Wallet\Traits\HasWallets;
use Illuminate\Database\Eloquent\Model;

class TenantInsuranceProvider extends Model implements WalletInterface
{
    use HasWallet;
    use HasWallets;

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

    public function bearerToken(): string
    {
        return (string) data_get($this->credentials, 'token', '');
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials, $key, $default);
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

    public function getOrCreateCurrencyWallet(string $currency = 'LYD'): Wallet
    {
        $normalizedCurrency = strtoupper($currency);
        $slug = 'INS_'.$normalizedCurrency;

        return $this->getWallet($slug) ?? $this->createWallet([
            'name' => $this->name.' '.$normalizedCurrency.' Wallet',
            'slug' => $slug,
            'meta' => [
                'currency' => $normalizedCurrency,
                'provider_type' => $this->provider_type,
            ],
        ]);
    }

    public function getBalance(string $currency = 'LYD'): float
    {
        return (float) $this->getOrCreateCurrencyWallet($currency)->balanceFloat;
    }
}
