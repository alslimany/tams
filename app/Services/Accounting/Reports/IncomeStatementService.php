<?php

namespace App\Services\Accounting\Reports;

use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\LedgerAccount;

/**
 * Profit & loss for a period:
 *   Revenue (4xxx) − COGS (5xxx) = Gross Profit
 *   Gross Profit − Purchases (6xxx) − Operating Expenses (7xxx) = Net Profit
 */
class IncomeStatementService
{
    /**
     * @return array{
     *   period: array{from: string, to: string},
     *   revenue: array{accounts: list<array{code: string, name: string, amount: float}>, total: float},
     *   cogs: array{accounts: list<array{code: string, name: string, amount: float}>, total: float},
     *   grossProfit: float,
     *   grossMargin: float,
     *   purchases: array{accounts: list<array{code: string, name: string, amount: float}>, total: float},
     *   opex: array{accounts: list<array{code: string, name: string, amount: float}>, total: float},
     *   netProfit: float
     * }
     */
    public function generate(string $from, string $to): array
    {
        // Revenue is credit-normal (positive sums); cost sections are debit-normal (negative sums).
        $revenue = $this->sumRange('4', $from, $to, debitNormal: false);
        $cogs = $this->sumRange('5', $from, $to, debitNormal: true);
        $purchases = $this->sumRange('6', $from, $to, debitNormal: true);
        $opex = $this->sumRange('7', $from, $to, debitNormal: true);

        $grossProfit = round($revenue['total'] - $cogs['total'], 3);
        $netProfit = round($grossProfit - $purchases['total'] - $opex['total'], 3);
        $grossMargin = $revenue['total'] > 0
            ? round(($grossProfit / $revenue['total']) * 100, 2)
            : 0.0;

        return [
            'period' => ['from' => $from, 'to' => $to],
            'revenue' => $revenue,
            'cogs' => $cogs,
            'grossProfit' => $grossProfit,
            'grossMargin' => $grossMargin,
            'purchases' => $purchases,
            'opex' => $opex,
            'netProfit' => $netProfit,
        ];
    }

    /**
     * @return array{accounts: list<array{code: string, name: string, amount: float}>, total: float}
     */
    private function sumRange(string $codePrefix, string $from, string $to, bool $debitNormal): array
    {
        $accounts = LedgerAccount::with('names')
            ->where('category', false)
            ->where('code', 'like', $codePrefix.'%')
            ->orderBy('code')
            ->get();

        $rows = [];
        $total = 0.0;

        foreach ($accounts as $account) {
            $net = JournalDetail::where('ledgerUuid', $account->ledgerUuid)
                ->whereHas('entry', fn ($q) => $q->whereDate('transDate', '>=', $from)->whereDate('transDate', '<=', $to))
                ->selectRaw('SUM(CAST(amount AS DECIMAL(20,3))) as net')
                ->value('net');

            $amount = round($debitNormal ? -(float) $net : (float) $net, 3);

            if (abs($amount) < 0.0005) {
                continue;
            }

            $rows[] = [
                'code' => (string) $account->code,
                'name' => $account->names->first()?->name ?? $account->code,
                'amount' => $amount,
            ];

            $total += $amount;
        }

        return [
            'accounts' => $rows,
            'total' => round($total, 3),
        ];
    }
}
