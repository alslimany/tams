<?php

namespace App\Services\Accounting\Reports;

use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\LedgerAccount;
use Illuminate\Support\Collection;

/**
 * Generates a trial balance report for the current tenant's ledger.
 *
 * Lists each account's closing balance as of a specific date. abivia stores debits
 * as negative amounts and credits as positive amounts; each account appears in
 * exactly one column (debit or credit). Total debits must equal total credits.
 */
class TrialBalanceReport
{
    /**
     * @return Collection<int, array{code: string, name: string, debit: float, credit: float, net: float}>
     */
    public function generate(string $asAtDate): Collection
    {
        $aggregates = JournalDetail::query()
            ->whereHas('entry', fn ($q) => $q->whereDate('transDate', '<=', $asAtDate))
            ->groupBy('ledgerUuid')
            ->selectRaw('ledgerUuid, SUM(CAST(amount AS DECIMAL(20,3))) as net')
            ->get()
            ->keyBy('ledgerUuid');

        return LedgerAccount::with('names')
            ->where('category', false)
            ->where('code', '!=', '')
            ->orderBy('code')
            ->get()
            ->map(function (LedgerAccount $account) use ($aggregates) {
                $net = round((float) ($aggregates->get($account->ledgerUuid)->net ?? 0), 3);

                return [
                    'code' => $account->code,
                    'name' => $account->names->first()?->name ?? $account->code,
                    'debit' => $net < 0 ? abs($net) : 0.0,
                    'credit' => $net > 0 ? $net : 0.0,
                    'net' => $net,
                ];
            });
    }
}
