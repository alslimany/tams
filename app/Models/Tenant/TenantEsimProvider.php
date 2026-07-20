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
            'usd_to_lyd_rate' => 'decimal:4',
        ];
    }

    public function usdToLydRate(): ?float
    {
        $rate = $this->usd_to_lyd_rate;

        if ($rate === null || (float) $rate <= 0) {
            return null;
        }

        return round((float) $rate, 4);
    }

    public function convertsUsdToLyd(): bool
    {
        return $this->usdToLydRate() !== null;
    }

    /**
     * Convert an L2 USD amount to the tenant selling currency when a rate is configured.
     *
     * @return array{price: float, currency: string, provider_price: float, provider_currency: string, exchange_rate: float|null}
     */
    public function presentUsdPrice(float $usdPrice): array
    {
        $providerPrice = round($usdPrice, 2);
        $rate = $this->usdToLydRate();

        if ($rate === null) {
            return [
                'price' => $providerPrice,
                'currency' => self::DEFAULT_CURRENCY,
                'provider_price' => $providerPrice,
                'provider_currency' => self::DEFAULT_CURRENCY,
                'exchange_rate' => null,
            ];
        }

        return [
            'price' => round($providerPrice * $rate, 2),
            'currency' => 'LYD',
            'provider_price' => $providerPrice,
            'provider_currency' => self::DEFAULT_CURRENCY,
            'exchange_rate' => $rate,
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
