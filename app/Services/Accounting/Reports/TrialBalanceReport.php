<?php

namespace App\Services\Accounting\Reports;

use Abivia\Ledger\Messages\Create;
use Abivia\Ledger\Messages\Report;
use Abivia\Ledger\Reports\TrialBalanceReport as AbiviaTrialBalanceReport;
use App\Services\Accounting\LedgerQueryService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Generates a trial balance report for the current tenant's ledger.
 *
 * Returns a flat collection of accounts with debit/credit totals.
 * The sum of all debits must equal the sum of all credits (double-entry invariant).
 */
class TrialBalanceReport
{
    public function __construct(
        private readonly LedgerQueryService $query,
    ) {}

    /**
     * Generate the trial balance as of now.
     *
     * @return Collection<string, array{code: string, name: string, debit: float, credit: float, net: float}>
     */
    public function generate(?Carbon $asOf = null): Collection
    {
        $asOf ??= Carbon::now();

        $message = Report::fromArray([
            'name' => 'trialBalance',
            'domain' => Create::DEFAULT_DOMAIN,
            'currency' => 'LYD',
            'toDate' => $asOf->format('Y-m-d'),
        ]);

        $reportData = (new AbiviaTrialBalanceReport)->collect($message);
        $prepared = (new AbiviaTrialBalanceReport)->prepare($reportData);

        /** @var Collection $accounts */
        $accounts = $prepared->get('accounts', collect());

        return $accounts->map(function ($account) {
            $balance = (float) $account->balance;

            return [
                'code' => $account->code,
                'name' => $account->name ?? $account->code,
                'debit' => $balance < 0 ? abs($balance) : 0.0,
                'credit' => $balance > 0 ? $balance : 0.0,
                'net' => $balance,
            ];
        });
    }
}
