<?php

namespace App\Services\Issuance;

use App\Models\Tenant;
use App\Services\Accounting\LedgerPostingService;
use Bavix\Wallet\Internal\Service\DispatcherServiceInterface;

/**
 * Orchestrates a settlement payment from a merchant tenant to a network agency tenant.
 *
 * Settlement clears the inter-tenant payable/receivable created during merchant issuance:
 *   - Merchant side: Dr 2200 (Agency Payable) / Cr 1120 (Merchant Wallet)
 *   - Agency side:   Dr 1110 (Operating Wallet) / Cr 1320 (Merchant Receivable)
 *
 * Each side runs in its own tenant context. Wallet movements are plain deposits/withdrawals
 * without ledger_accounts meta — the explicit postSettlementEntry() calls are the canonical
 * ledger records (same pattern as DirectAgencyIssuanceService).
 */
class SettlementService
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * Settle what a merchant owes a network agency.
     *
     * @param  string  $merchantTenantId  The merchant tenant paying.
     * @param  string  $agencyTenantId  The agency tenant receiving.
     * @param  float  $amount  Settlement amount.
     * @param  string  $batchReference  Unique batch reference for this settlement run.
     */
    public function settleMerchantToAgency(
        string $merchantTenantId,
        string $agencyTenantId,
        float $amount,
        string $batchReference,
    ): void {
        // ── Merchant side ────────────────────────────────────────────────────
        $merchantTenant = Tenant::findOrFail($merchantTenantId);
        $merchantTenant->run(function () use ($merchantTenantId, $agencyTenantId, $amount, $batchReference) {
            app(DispatcherServiceInterface::class)->flush();

            $merchant = Tenant::findOrFail($merchantTenantId);
            $merchantWallet = $merchant->getWallet('merchant');

            // Deduct merchant wallet (no ledger_accounts meta — explicit entry below).
            $merchantWallet->withdrawFloat($amount, [
                'order_type' => 'settlement',
                'tx_type' => 'settlement',
                'reference' => $batchReference,
                'agency_tenant' => $agencyTenantId,
            ]);

            // Post merchant-side settlement journal entry.
            $this->ledger->postMerchantSettlementEntry(
                amount: $amount,
                batchReference: $batchReference,
                agencyTenantId: $agencyTenantId,
            );
        });
        tenancy()->end();

        // ── Agency side ──────────────────────────────────────────────────────
        $agencyTenant = Tenant::findOrFail($agencyTenantId);
        $agencyTenant->run(function () use ($merchantTenantId, $agencyTenantId, $amount, $batchReference) {
            app(DispatcherServiceInterface::class)->flush();

            $agency = Tenant::findOrFail($agencyTenantId);
            $agencyWallet = $agency->getWallet('operating');

            // Credit agency operating wallet (no ledger_accounts meta — explicit entry below).
            $agencyWallet->depositFloat($amount, [
                'order_type' => 'settlement',
                'tx_type' => 'settlement',
                'reference' => $batchReference,
                'merchant_tenant' => $merchantTenantId,
            ]);

            // Post agency-side settlement journal entry.
            $this->ledger->postAgencySettlementEntry(
                amount: $amount,
                batchReference: $batchReference,
                merchantTenantId: $merchantTenantId,
            );
        });
        tenancy()->end();
    }
}
