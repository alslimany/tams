<?php

namespace App\Services\Wallet;

use App\Models\Tenant;
use App\Models\TenantProvider;

/**
 * Provisions the named wallets for tenants and providers.
 *
 * Each wallet carries a `ledger_account` meta key that the LedgerPostingService
 * (Phase 4) uses to determine which CoA account to debit/credit.
 *
 * Must be called inside the tenant context (i.e. after $tenant->run() or within
 * a tenant request) because wallets are stored in the tenant database.
 */
class WalletProvisioningService
{
    /**
     * Provision the operating wallet for a direct or network agency tenant.
     * Idempotent — skips creation if the wallet already exists.
     */
    public function provisionAgencyWallets(Tenant $tenant): void
    {
        if ($tenant->getWallet('operating') !== null) {
            return;
        }

        $tenant->createWallet([
            'name' => 'Operating Wallet',
            'slug' => 'operating',
            'meta' => [
                'ledger_account' => '1110',
                'type' => 'operating',
            ],
        ]);
    }

    /**
     * Provision the dedicated wallet for a merchant tenant (type = merchant).
     * Idempotent — skips creation if the wallet already exists.
     */
    public function provisionMerchantWallet(Tenant $tenant): void
    {
        if ($tenant->getWallet('merchant') !== null) {
            return;
        }

        $tenant->createWallet([
            'name' => 'Merchant Wallet',
            'slug' => 'merchant',
            'meta' => [
                'ledger_account' => '1120',
                'type' => 'merchant',
            ],
        ]);
    }

    /**
     * Provision the wallet for a TenantProvider (airline/hotel/insurance/esim).
     * Idempotent — skips creation if the wallet already exists.
     *
     * @param  TenantProvider  $provider  Must have `provider_type` set.
     */
    public function provisionProviderWallet(TenantProvider $provider): void
    {
        $slugMap = [
            'videcom' => ['slug' => 'airline-provider',   'ledger' => '1210'],
            'amadeus' => ['slug' => 'airline-provider',   'ledger' => '1210'],
            'ndc' => ['slug' => 'airline-provider',   'ledger' => '1210'],
            'airline' => ['slug' => 'airline-provider',   'ledger' => '1210'],
            'hotel' => ['slug' => 'hotel-provider',     'ledger' => '1220'],
            '3t' => ['slug' => 'hotel-provider',     'ledger' => '1220'],
            'insurance' => ['slug' => 'insurance-provider', 'ledger' => '1230'],
            'albaraka' => ['slug' => 'insurance-provider', 'ledger' => '1230'],
            'esim' => ['slug' => 'esim-provider',      'ledger' => '1240'],
        ];

        $type = $provider->provider_type ?? 'airline';
        $cfg = $slugMap[$type] ?? ['slug' => 'provider-wallet', 'ledger' => '1200'];

        if ($provider->getWallet($cfg['slug']) !== null) {
            return;
        }

        $provider->createWallet([
            'name' => ucfirst($type).' Provider Wallet',
            'slug' => $cfg['slug'],
            'meta' => [
                'ledger_account' => $cfg['ledger'],
                'type' => 'provider',
                'provider_type' => $type,
            ],
        ]);
    }

    /**
     * Provision all wallets for a tenant based on its type.
     * Convenience method called from BootstrapTenantLedgerJob.
     */
    public function provisionForTenant(Tenant $tenant): void
    {
        if ($tenant->type === 'merchant') {
            $this->provisionMerchantWallet($tenant);
        } else {
            $this->provisionAgencyWallets($tenant);
        }
    }
}
