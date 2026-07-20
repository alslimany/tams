<?php

namespace App\Services\Accounting\Reports;

use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\LedgerAccount;
use Abivia\Ledger\Models\SubJournal;

/**
 * Account-by-account statement with opening balance, period lines and
 * a running balance per line.
 *
 * abivia stores debits as negative amounts and credits as positive amounts;
 * running balances follow the credit-positive convention used by AccountDetail.
 */
class GeneralLedgerService
{
    /**
     * @param  list<string>|null  $accountCodes  Restrict to specific account codes.
     * @return list<array{code: string, name: string, type: string, openingBalance: float, lines: list<array<string, mixed>>, closingBalance: float, totalDebit: float, totalCredit: float}>
     */
    public function generate(string $from, string $to, ?array $accountCodes = null, bool $skipInactive = true): array
    {
        $accounts = LedgerAccount::with('names')
            ->where('category', false)
            ->where('code', '!=', '')
            ->when($accountCodes, fn ($query) => $query->whereIn('code', $accountCodes))
            ->orderBy('code')
            ->get();

        $subJournalMap = SubJournal::all()->pluck('code', 'subJournalUuid');

        $result = [];

        foreach ($accounts as $account) {
            $openingSum = JournalDetail::where('ledgerUuid', $account->ledgerUuid)
                ->whereHas('entry', fn ($q) => $q->whereDate('transDate', '<', $from))
                ->selectRaw('SUM(CAST(amount AS DECIMAL(20,3))) as net')
                ->value('net');
            $openingBalance = round((float) $openingSum, 3);

            $lines = JournalDetail::where('ledgerUuid', $account->ledgerUuid)
                ->whereHas('entry', fn ($q) => $q->whereDate('transDate', '>=', $from)->whereDate('transDate', '<=', $to))
                ->with('entry')
                ->orderBy('journalEntryId')
                ->get()
                ->map(function (JournalDetail $detail) use ($subJournalMap) {
                    $amount = (float) $detail->amount;
                    $entry = $detail->entry;
                    $extra = is_string($entry->extra) ? json_decode($entry->extra, true) : ($entry->extra ?? []);

                    return [
                        'date' => $entry->transDate->toDateString(),
                        'description' => $entry->description ?? '',
                        'journal' => $subJournalMap[$entry->subJournalUuid] ?? ($extra['journal'] ?? 'GEN'),
                        'reference' => (string) ($extra['reference'] ?? ''),
                        'debit' => $amount < 0 ? abs($amount) : null,
                        'credit' => $amount > 0 ? $amount : null,
                        'entryId' => $entry->journalEntryId,
                    ];
                });

            if ($skipInactive && $lines->isEmpty() && abs($openingBalance) < 0.0005) {
                continue;
            }

            $running = $openingBalance;
            $linesWithBalance = $lines->map(function (array $line) use (&$running) {
                $running += ($line['credit'] ?? 0) - ($line['debit'] ?? 0);

                return array_merge($line, ['runningBalance' => round($running, 3)]);
            })->values()->all();

            $result[] = [
                'code' => $account->code,
                'name' => $account->names->first()?->name ?? $account->code,
                'type' => $this->accountType($account->code),
                'openingBalance' => $openingBalance,
                'lines' => $linesWithBalance,
                'closingBalance' => round($running, 3),
                'totalDebit' => round($lines->sum('debit'), 3),
                'totalCredit' => round($lines->sum('credit'), 3),
            ];
        }

        return $result;
    }

    private function accountType(string $code): string
    {
        return match (substr($code, 0, 1)) {
            '1', '8' => 'asset',
            '2' => 'liability',
            '3' => 'equity',
            '4' => 'revenue',
            '6' => 'purchase',
            default => 'expense',
        };
    }
}
