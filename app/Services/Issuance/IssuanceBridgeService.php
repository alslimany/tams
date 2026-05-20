<?php

namespace App\Services\Issuance;

use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Services\Accounting\LedgerPostingService;

/**
 * Handles cross-tenant wallet deductions for network merchant issuances.
 *
 * When a merchant issues through a network agency:
 *   1. The merchant's wallet (in the merchant tenant DB) is debited for the wholesale price.
 *   2. The agency's provider wallet (in the agency tenant DB) is debited for the provider cost.
 *
 * Each deduction runs inside the correct tenant context so that bavix wallet
 * records land in the right tenant database and the wallet event listener
 * auto-posts the corresponding ledger entry in that tenant's ledger.
 */
class IssuanceBridgeService
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * Deduct the merchant wallet and the agency provider wallet in their respective tenant contexts.
     *
     * @param  string  $merchantTenantId  Tenant ID of the merchant.
     * @param  string  $agencyTenantId  Tenant ID of the network agency.
     * @param  int  $providerId  ID of the TenantProvider whose wallet to debit.
     * @param  float  $merchantDeductAmount  Wholesale price debited from merchant wallet.
     * @param  float  $agencyProviderDeductAmount  Provider cost debited from agency provider wallet.
     * @param  string  $productType  airline | hotel | insurance | esim
     * @param  string  $orderId  The order ID (may be a placeholder before order creation).
     * @param  string  $providerRef  Provider-issued reference (PNR, booking ID, etc.).
     */
    public function deductBothTenants(
        string $merchantTenantId,
        string $agencyTenantId,
        int $providerId,
        float $merchantDeductAmount,
        float $agencyProviderDeductAmount,
        string $productType,
        string $orderId,
        string $providerRef,
    ): void {
        // Step 1: Deduct merchant wallet in merchant tenant context.
        $this->runInTenant($merchantTenantId, function () use (
            $merchantTenantId, $merchantDeductAmount, $productType, $orderId, $providerRef, $agencyTenantId
        ) {
            /** @var \App\Models\Tenant $merchant */
            $merchant = Tenant::findOrFail($merchantTenantId);
            $wallet = $merchant->getWallet('merchant');

            $wallet->withdrawFloat($merchantDeductAmount, [
                'order_id' => $orderId,
                'order_type' => $productType,
                'tx_type' => 'issuance',
                'ledger_accounts' => [
                    'debit' => '5500',
                    'credit' => '1120',
                ],
                'reference' => $providerRef,
                'agency_tenant_id' => $agencyTenantId,
            ]);
        });

        // Step 2: Deduct agency provider wallet in agency tenant context.
        $this->runInTenant($agencyTenantId, function () use (
            $providerId, $agencyProviderDeductAmount, $productType, $orderId, $providerRef
        ) {
            $provider = TenantProvider::findOrFail($providerId);
            $walletSlug = $this->resolveProviderWalletSlug($productType);

            $provider->getWallet($walletSlug)->withdrawFloat($agencyProviderDeductAmount, [
                'order_id' => $orderId,
                'order_type' => $productType,
                'tx_type' => 'issuance',
                'ledger_accounts' => [
                    'debit' => $this->ledger->costAccount($productType),
                    'credit' => $this->ledger->providerWalletAccount($productType),
                ],
                'reference' => $providerRef,
            ]);
        });
    }

    /**
     * Run a callback inside the given tenant's context, then end tenancy.
     */
    private function runInTenant(string $tenantId, callable $callback): void
    {
        $tenant = Tenant::findOrFail($tenantId);
        tenancy()->initialize($tenant);

        try {
            $callback();
        } finally {
            tenancy()->end();
        }
    }

    /**
     * Resolve the wallet slug for a given product type's provider wallet.
     */
    private function resolveProviderWalletSlug(string $productType): string
    {
        return match ($productType) {
            'airline' => 'airline-provider',
            'hotel' => 'hotel-provider',
            'insurance' => 'insurance-provider',
            'esim' => 'esim-provider',
            default => 'provider-wallet',
        };
    }
}
