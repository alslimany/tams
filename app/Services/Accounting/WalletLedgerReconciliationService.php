<?php

namespace App\Services\Accounting;

use App\Models\Tenant;
use App\Models\TenantProvider;
use Bavix\Wallet\Models\Wallet;

/**
 * Reconciles wallet balances against their corresponding ledger account balances.
 *
 * Each wallet-backed asset account should have a ledger balance equal to the
 * wallet's current balance. A mismatch indicates a data integrity issue
 * (e.g. a wallet transaction without a corresponding ledger entry).
 *
 * Account mapping:
 *   1110 → Agency operating wallet  (Tenant, wallet='operating')
 *   1120 → Merchant wallet          (Tenant type=merchant, wallet='merchant')
 *   1210 → Airline provider wallet  (TenantProvider, wallet='airline-provider')
 *   1220 → Hotel provider wallet    (TenantProvider, wallet='hotel-provider')
 *   1230 → Insurance provider wallet(TenantProvider, wallet='insurance-provider')
 *   1240 → eSIM provider wallet     (TenantProvider, wallet='esim-provider')
 */
class WalletLedgerReconciliationService
{
    public function __construct(
        private readonly LedgerQueryService $query,
    ) {}

    /**
     * Reconcile all wallet-backed ledger accounts for the current tenant context.
     *
     * @return array<string, array{wallet_balance: float, ledger_balance: float, difference: float, status: string}>
     */
    public function reconcile(): array
    {
        $results = [];

        // 1110 — Agency operating wallet
        $results['1110'] = $this->reconcileAccount(
            '1110',
            $this->getWalletBalance('operating', 'tenant'),
        );

        // 1120 — Merchant wallet
        $results['1120'] = $this->reconcileAccount(
            '1120',
            $this->getWalletBalance('merchant', 'tenant'),
        );

        // Provider wallets 1210–1240
        $providerWallets = [
            '1210' => 'airline-provider',
            '1220' => 'hotel-provider',
            '1230' => 'insurance-provider',
            '1240' => 'esim-provider',
        ];

        foreach ($providerWallets as $accountCode => $walletSlug) {
            $results[$accountCode] = $this->reconcileAccount(
                $accountCode,
                $this->getProviderWalletBalance($walletSlug),
            );
        }

        return $results;
    }

    /**
     * Build a single reconciliation row.
     */
    private function reconcileAccount(string $accountCode, float $walletBalance): array
    {
        // Ledger balance for asset accounts is the net debit balance (negative in abivia = debit).
        // accountBalanceSigned() returns negative for debit-balance accounts.
        // We want the absolute value for comparison with wallet balance.
        $ledgerBalance = abs($this->query->accountBalanceSigned($accountCode));
        $difference = round(abs($walletBalance - $ledgerBalance), 3);

        return [
            'wallet_balance' => round($walletBalance, 3),
            'ledger_balance' => round($ledgerBalance, 3),
            'difference' => $difference,
            'status' => $difference < 0.001 ? 'matched' : 'MISMATCH',
        ];
    }

    /**
     * Get the sum of all wallet balances for a given slug on the current tenant model.
     * bavix stores balance as integer scaled by 10^decimal_places (default 2).
     */
    private function getWalletBalance(string $slug, string $holderType): float
    {
        $wallets = Wallet::where('slug', $slug)
            ->where('holder_type', (new Tenant)->getMorphClass())
            ->get();

        return $wallets->sum(fn ($w) => (float) $w->balance / (10 ** $w->decimal_places));
    }

    /**
     * Get the sum of all provider wallet balances for a given slug.
     */
    private function getProviderWalletBalance(string $slug): float
    {
        $wallets = Wallet::where('slug', $slug)
            ->where('holder_type', (new TenantProvider)->getMorphClass())
            ->get();

        return $wallets->sum(fn ($w) => (float) $w->balance / (10 ** $w->decimal_places));
    }
}
