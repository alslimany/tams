<?php

namespace App\Services\Accounting\Reports;

use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\LedgerAccount;

/**
 * Snapshot of financial position at a specific date.
 *
 * Uses cumulative balances from inception to the as-at date. Since P&L accounts
 * are not closed to equity, the calculated current-period profit is added to the
 * equity section so that Assets = Liabilities + Equity always balances.
 */
class BalanceSheetService
{
    /**
     * @return array{
     *   asAtDate: string,
     *   assets: array{accounts: list<array{code: string, name: string, balance: float}>, total: float},
     *   liabilities: array{accounts: list<array{code: string, name: string, balance: float}>, total: float},
     *   equity: array{accounts: list<array{code: string, name: string, balance: float}>, total: float, calculatedProfit: float},
     *   totals: array{assets: float, liabilities_and_equity: float},
     *   isBalanced: bool
     * }
     */
    public function generate(string $asAtDate): array
    {
        $balances = $this->cumulativeBalances($asAtDate);

        // Debit-normal sections are shown positive when their net sum is negative
        // (abivia stores debits negative). Settlement clearing (8xxx) is debit-normal.
        $assets = $this->section($balances, ['1', '8'], debitNormal: true);
        $liabilities = $this->section($balances, ['2'], debitNormal: false);
        $equity = $this->section($balances, ['3'], debitNormal: false);

        // Current-period P&L = revenue (4xxx) − COGS (5xxx) − purchases (6xxx) − opex (7xxx).
        $profit = 0.0;
        foreach ($balances as $row) {
            $prefix = substr($row['code'], 0, 1);
            if (in_array($prefix, ['4', '5', '6', '7'], true)) {
                // Credit-positive sum: revenue positive, expenses negative — the raw
                // sum across all P&L accounts is exactly the net profit.
                $profit += $row['net'];
            }
        }
        $profit = round($profit, 3);

        $equity['calculatedProfit'] = $profit;
        $equity['total'] = round($equity['total'] + $profit, 3);

        $totalAssets = $assets['total'];
        $totalLiabilitiesAndEquity = round($liabilities['total'] + $equity['total'], 3);

        return [
            'asAtDate' => $asAtDate,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'totals' => [
                'assets' => $totalAssets,
                'liabilities_and_equity' => $totalLiabilitiesAndEquity,
            ],
            'isBalanced' => abs($totalAssets - $totalLiabilitiesAndEquity) < 0.01,
        ];
    }

    /**
     * @return list<array{code: string, name: string, net: float}>
     */
    private function cumulativeBalances(string $asAtDate): array
    {
        $accounts = LedgerAccount::with('names')
            ->where('category', false)
            ->where('code', '!=', '')
            ->orderBy('code')
            ->get();

        $rows = [];

        foreach ($accounts as $account) {
            $net = JournalDetail::where('ledgerUuid', $account->ledgerUuid)
                ->whereHas('entry', fn ($q) => $q->whereDate('transDate', '<=', $asAtDate))
                ->selectRaw('SUM(CAST(amount AS DECIMAL(20,3))) as net')
                ->value('net');

            $rows[] = [
                'code' => (string) $account->code,
                'name' => $account->names->first()?->name ?? $account->code,
                'net' => round((float) $net, 3),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{code: string, name: string, net: float}>  $balances
     * @param  list<string>  $prefixes
     * @return array{accounts: list<array{code: string, name: string, balance: float}>, total: float}
     */
    private function section(array $balances, array $prefixes, bool $debitNormal): array
    {
        $accounts = [];
        $total = 0.0;

        foreach ($balances as $row) {
            if (! in_array(substr($row['code'], 0, 1), $prefixes, true)) {
                continue;
            }

            // Present debit-normal balances as positive numbers.
            $balance = $debitNormal ? round(-$row['net'], 3) : $row['net'];

            if (abs($balance) < 0.0005) {
                continue;
            }

            $accounts[] = [
                'code' => $row['code'],
                'name' => $row['name'],
                'balance' => $balance,
            ];

            $total += $balance;
        }

        return [
            'accounts' => $accounts,
            'total' => round($total, 3),
        ];
    }
}
