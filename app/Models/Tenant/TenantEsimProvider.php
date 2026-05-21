<?php

namespace App\Models\Tenant;

use Bavix\Wallet\Interfaces\Wallet as WalletInterface;
use Bavix\Wallet\Models\Wallet;
use Bavix\Wallet\Traits\HasWallet;
use Bavix\Wallet\Traits\HasWallets;
use Illuminate\Database\Eloquent\Model;

class TenantEsimProvider extends Model implements WalletInterface
{
    use HasWallet;
    use HasWallets;

    public const DEFAULT_CURRENCY = 'USD';

    protected $guarded = [];

    protected $attributes = [
        'currency' => self::DEFAULT_CURRENCY,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credentials' => 'array',
            'is_active' => 'boolean',
            'commission_esim' => 'decimal:2',
        ];
    }

    public function commissionForProductType(string $productType): float
    {
        return match (strtolower($productType)) {
            'esim' => (float) $this->commission_esim,
            default => 0.0,
        };
    }

    public function markupForProductType(string $productType): float
    {
        return $this->commissionForProductType($productType);
    }

    public function getOrCreateCurrencyWallet(string $currency = 'USD'): Wallet
    {
        $normalizedCurrency = strtoupper($currency);
        $slug = 'ESIM_'.$normalizedCurrency;

        return $this->getWallet($slug) ?? $this->createWallet([
            'name' => $this->name.' '.$normalizedCurrency.' Wallet',
            'slug' => $slug,
            'meta' => [
                'currency' => $normalizedCurrency,
                'provider_type' => $this->provider_type,
                'product_type' => 'esim',
            ],
        ]);
    }

    public function getBalance(string $currency = 'USD'): float
    {
        return (float) $this->getOrCreateCurrencyWallet($currency)->balanceFloat;
    }

    public function providerCurrency(): string
    {
        $currency = strtoupper((string) ($this->currency ?: self::DEFAULT_CURRENCY));

        return $currency !== '' ? $currency : self::DEFAULT_CURRENCY;
    }
}
