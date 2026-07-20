<?php

namespace App\Models\Tenant;

use Bavix\Wallet\Interfaces\Wallet as WalletInterface;
use Bavix\Wallet\Models\Wallet;
use Bavix\Wallet\Traits\HasWallet;
use Bavix\Wallet\Traits\HasWallets;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class TenantInsuranceProvider extends Model implements WalletInterface
{
    use HasWallet;
    use HasWallets;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'commission_compulsory' => 'decimal:2',
            'commission_travel' => 'decimal:2',
            'commission_orange' => 'decimal:2',
        ];
    }

    protected function credentials(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => self::decodeCredentials($value),
            set: fn (?array $value) => $value === null
                ? null
                : encrypt(json_encode($value, JSON_THROW_ON_ERROR)),
        );
    }

    public function bearerToken(): string
    {
        return (string) data_get($this->credentials, 'token', '');
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials, $key, $default);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decodeCredentials(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return self::normalizeCredentialSecrets($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $legacy = json_decode($value, true);
        if (is_array($legacy) && array_key_exists('token', $legacy)) {
            return self::normalizeCredentialSecrets($legacy);
        }

        try {
            $decrypted = decrypt($value);

            if (is_array($decrypted)) {
                return self::normalizeCredentialSecrets($decrypted);
            }

            if (is_string($decrypted)) {
                $decoded = json_decode($decrypted, true);
                if (is_array($decoded)) {
                    return self::normalizeCredentialSecrets($decoded);
                }
            }
        } catch (\Throwable) {
            // Not an encrypted credentials payload.
        }

        return is_array($legacy) ? self::normalizeCredentialSecrets($legacy) : null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    protected static function normalizeCredentialSecrets(array $credentials): array
    {
        if (isset($credentials['token']) && is_string($credentials['token']) && $credentials['token'] !== '') {
            $credentials['token'] = self::decryptSecret($credentials['token']);
        }

        return $credentials;
    }

    protected static function decryptSecret(string $value): string
    {
        if (! self::looksEncrypted($value)) {
            return $value;
        }

        try {
            $decrypted = decrypt($value);

            if (is_string($decrypted)) {
                return $decrypted;
            }
        } catch (\Throwable) {
            // Fall back to decryptString for encryptString() payloads.
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }

    protected static function looksEncrypted(string $value): bool
    {
        return str_starts_with($value, 'eyJpdiI6') || str_starts_with($value, '{"iv"');
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
