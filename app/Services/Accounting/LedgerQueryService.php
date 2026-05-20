<?php

namespace App\Services\Accounting;

use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\LedgerAccount;

/**
 * Read-only queries against the abivia ledger.
 *
 * abivia stores amounts as signed BCD strings in JournalDetail.amount:
 *   - Debits  → negative value
 *   - Credits → positive value
 *
 * A normal credit-balance account (liability, revenue) has a positive running total.
 * A normal debit-balance account (asset, expense) has a negative running total.
 *
 * accountBalance() returns the absolute value of the net sum so callers always
 * receive a positive number representing the magnitude of the balance.
 */
class LedgerQueryService
{
    /**
     * Return the net balance for a given account code (absolute value).
     *
     * @param  string  $code  Chart-of-accounts code, e.g. '4100', '1110'.
     */
    public function accountBalance(string $code): float
    {
        $account = LedgerAccount::where('code', $code)->first();

        if ($account === null) {
            return 0.0;
        }

        $sum = JournalDetail::where('ledgerUuid', $account->ledgerUuid)
            ->selectRaw('SUM(CAST(amount AS DECIMAL(20,3))) as net')
            ->value('net');

        return abs((float) $sum);
    }

    /**
     * Return the signed net balance for a given account code.
     *
     * Positive = net credit balance (revenue, liability).
     * Negative = net debit balance (asset, expense).
     */
    public function accountBalanceSigned(string $code): float
    {
        $account = LedgerAccount::where('code', $code)->first();

        if ($account === null) {
            return 0.0;
        }

        $sum = JournalDetail::where('ledgerUuid', $account->ledgerUuid)
            ->selectRaw('SUM(CAST(amount AS DECIMAL(20,3))) as net')
            ->value('net');

        return (float) $sum;
    }

    /**
     * Return the total debits posted to an account (sum of negative amounts, returned positive).
     */
    public function accountDebits(string $code): float
    {
        $account = LedgerAccount::where('code', $code)->first();

        if ($account === null) {
            return 0.0;
        }

        $sum = JournalDetail::where('ledgerUuid', $account->ledgerUuid)
            ->whereRaw('CAST(amount AS DECIMAL(20,3)) < 0')
            ->selectRaw('SUM(ABS(CAST(amount AS DECIMAL(20,3)))) as total')
            ->value('total');

        return (float) $sum;
    }

    /**
     * Return the total credits posted to an account.
     */
    public function accountCredits(string $code): float
    {
        $account = LedgerAccount::where('code', $code)->first();

        if ($account === null) {
            return 0.0;
        }

        $sum = JournalDetail::where('ledgerUuid', $account->ledgerUuid)
            ->whereRaw('CAST(amount AS DECIMAL(20,3)) > 0')
            ->selectRaw('SUM(CAST(amount AS DECIMAL(20,3))) as total')
            ->value('total');

        return (float) $sum;
    }
}
