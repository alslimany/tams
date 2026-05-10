<?php

namespace App\Models\Tenant;

use Bavix\Wallet\Interfaces\Wallet as WalletInterface;
use Bavix\Wallet\Models\Wallet;
use Bavix\Wallet\Traits\HasWallet;
use Bavix\Wallet\Traits\HasWallets;
use Illuminate\Database\Eloquent\Model;

class TenantHotelProvider extends Model implements WalletInterface
{
    use HasWallet;
    use HasWallets;

    public const DEFAULT_CURRENCY = 'LYD';

    protected $guarded = [];

    protected $attributes = [
        'currency' => self::DEFAULT_CURRENCY,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credentials' => 'array',
            'civility_codes' => 'array',
            'is_active' => 'boolean',
            'commission_hotel' => 'decimal:2',
        ];
    }

    /**
     * Returns the configured civility codes or the 3T defaults.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function resolvedCivilityCodes(): array
    {
        $codes = is_array($this->civility_codes) && count($this->civility_codes) > 0
            ? $this->civility_codes
            : ['Mr', 'Mme', 'Mlle', 'Enf'];

        return array_values(array_map(
            fn (string $code): array => ['value' => $code, 'label' => $code],
            array_filter($codes, fn (mixed $c): bool => is_string($c) && $c !== ''),
        ));
    }

    public function commissionForProductType(string $productType): float
    {
        return match (strtolower($productType)) {
            'hotel', 'hotels' => (float) $this->commission_hotel,
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
        $slug = 'HOTEL_'.$normalizedCurrency;

        return $this->getWallet($slug) ?? $this->createWallet([
            'name' => $this->name.' '.$normalizedCurrency.' Wallet',
            'slug' => $slug,
            'meta' => [
                'currency' => $normalizedCurrency,
                'provider_type' => $this->provider_type,
                'product_type' => 'hotel',
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
