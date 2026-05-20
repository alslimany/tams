<?php

namespace App\Audit;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class AuditReportGenerator
{
    public function generate(string $sessionFilePath): array
    {
        $raw = json_decode(File::get($sessionFilePath), true);
        $events = collect($raw['events']);

        return [
            'meta' => $this->buildMeta($raw, $events),
            'flow_summary' => $this->buildFlowSummary($events),
            'financial_numbers' => $this->buildFinancialNumbers($events),
            'wallet_movements' => $this->buildWalletMovements($events),
            'ledger_entries' => $this->buildLedgerEntries($events),
            'balance_checks' => $this->buildBalanceChecks($events),
            'accounting_checks' => $this->runAccountingChecks($events),
            'provider_api' => $this->buildProviderApiSummary($events),
            'anomalies' => $this->detectAnomalies($events),
        ];
    }

    private function buildMeta(array $raw, Collection $events): array
    {
        $start = $events->firstWhere('event_type', 'session_start');
        $end = $events->firstWhere('event_type', 'session_end');

        return [
            'session_id' => $raw['session_id'],
            'label' => $start['data']['label'] ?? 'unnamed',
            'tenant_id' => $start['data']['tenant_id'] ?? null,
            'started_at' => $start['data']['started_at'] ?? null,
            'php_version' => $start['data']['php_version'] ?? null,
            'app_env' => $start['data']['app_env'] ?? null,
            'total_events' => $end['data']['total_events'] ?? count($raw['events']),
            'total_elapsed_ms' => $end['data']['total_elapsed_ms'] ?? null,
        ];
    }

    private function buildFlowSummary(Collection $events): array
    {
        return [
            'http_requests' => $events->where('event_type', 'http_request')->count(),
            'wallet_transactions' => $events->where('event_type', 'wallet_transaction')->count(),
            'journal_entries' => $events->where('event_type', 'journal_entry_posted')->count(),
            'orders_created' => $events->where('event_type', 'order_created')->count(),
            'provider_api_calls' => $events->where('event_type', 'provider_api_call')->count(),
            'provider_api_success' => $events->where('event_type', 'provider_api_success')->count(),
            'provider_api_failure' => $events->where('event_type', 'provider_api_failure')->count(),
            'exceptions' => $events->where('event_type', 'exception')->count(),
        ];
    }

    private function buildFinancialNumbers(Collection $events): array
    {
        $orders = $events->where('event_type', 'order_created')->pluck('data');

        $byProduct = $orders->groupBy('product_type')->map(function (Collection $group) {
            $selling = $group->sum('selling_price');

            return [
                'count' => $group->count(),
                'total_selling' => round($selling, 3),
                'total_vat' => round($group->sum('vat_amount'), 3),
                'total_net' => round($selling - $group->sum('vat_amount'), 3),
                'total_cost' => round($group->sum('provider_cost'), 3),
                'total_margin' => round($group->sum('gross_margin'), 3),
                'total_commission' => round($group->sum('commission'), 3),
                'avg_margin_pct' => $selling > 0
                    ? round(($group->sum('gross_margin') / $selling) * 100, 2)
                    : 0,
            ];
        })->toArray();

        $totalSelling = $orders->sum('selling_price');

        return [
            'by_product' => $byProduct,
            'totals' => [
                'selling' => round($totalSelling, 3),
                'vat' => round($orders->sum('vat_amount'), 3),
                'net' => round($totalSelling - $orders->sum('vat_amount'), 3),
                'cost' => round($orders->sum('provider_cost'), 3),
                'margin' => round($orders->sum('gross_margin'), 3),
                'commission' => round($orders->sum('commission'), 3),
            ],
        ];
    }

    private function buildWalletMovements(Collection $events): array
    {
        return $events->where('event_type', 'wallet_transaction')
            ->pluck('data')
            ->map(fn (array $tx) => [
                'wallet' => $tx['wallet_slug'],
                'type' => $tx['type'],
                'amount' => $tx['amount'],
                'balance_after' => $tx['balance_after'],
                'tx_type' => $tx['meta']['tx_type'] ?? 'unknown',
                'order_id' => $tx['meta']['order_id'] ?? null,
                'ledger_debit' => $tx['meta']['ledger_accounts']['debit'] ?? null,
                'ledger_credit' => $tx['meta']['ledger_accounts']['credit'] ?? null,
                'has_ledger_meta' => isset($tx['meta']['ledger_accounts']),
            ])
            ->values()
            ->toArray();
    }

    private function buildLedgerEntries(Collection $events): array
    {
        return $events->where('event_type', 'journal_entry_posted')
            ->pluck('data')
            ->map(fn (array $e) => [
                'reference' => $e['reference'],
                'description' => $e['description'],
                'journal' => $e['journal'],
                'date' => $e['date'],
                'total_debit' => $e['total_debit'],
                'total_credit' => $e['total_credit'],
                'is_balanced' => $e['is_balanced'],
                'lines' => $e['lines'],
            ])
            ->values()
            ->toArray();
    }

    private function buildBalanceChecks(Collection $events): array
    {
        $lastAfter = $events->where('event_type', 'wallet_snapshot_after')->last();

        if (! $lastAfter) {
            return [];
        }

        $wallets = $lastAfter['data']['wallets'] ?? [];
        $accounts = $lastAfter['data']['ledger_accounts'] ?? [];

        // Map wallet slug fragments to ledger account codes
        $ledgerMap = [
            'AIR_LYD' => '1210',
            'HTL_LYD' => '1220',
            'INS_LYD' => '1230',
            'ESM_LYD' => '1240',
        ];

        $checks = [];

        foreach ($ledgerMap as $slugFragment => $accountCode) {
            $walletEntry = collect($wallets)->first(
                fn ($w) => str_contains($w['slug'] ?? '', $slugFragment)
            );

            $walletBalance = $walletEntry['balance'] ?? null;
            $ledgerBalance = $accounts[$accountCode] ?? null;

            if ($walletBalance !== null && $ledgerBalance !== null) {
                $diff = round(abs($walletBalance - $ledgerBalance), 3);
                $checks[] = [
                    'wallet_slug' => $slugFragment,
                    'account_code' => $accountCode,
                    'wallet_balance' => $walletBalance,
                    'ledger_balance' => $ledgerBalance,
                    'difference' => $diff,
                    'status' => $diff < 0.001 ? 'MATCHED' : 'MISMATCH',
                ];
            }
        }

        return $checks;
    }

    private function runAccountingChecks(Collection $events): array
    {
        $checks = [];
        $entries = $events->where('event_type', 'journal_entry_posted')->pluck('data');
        $txs = $events->where('event_type', 'wallet_transaction')->pluck('data');
        $orders = $events->where('event_type', 'order_created')->pluck('data');

        // CHECK 1: Every journal entry must be balanced
        $unbalanced = $entries->filter(fn ($e) => ! $e['is_balanced']);
        $checks['all_entries_balanced'] = [
            'passed' => $unbalanced->isEmpty(),
            'message' => $unbalanced->isEmpty()
                ? 'All journal entries are balanced'
                : "FAIL: {$unbalanced->count()} unbalanced entries found",
            'failing' => $unbalanced->pluck('reference')->values()->toArray(),
        ];

        // CHECK 2: Every wallet withdrawal must carry ledger_accounts in meta
        $withdrawals = $txs->where('type', 'withdraw');
        $missingMeta = $withdrawals->filter(fn ($tx) => ! $tx['has_ledger_meta']);
        $checks['all_withdrawals_have_ledger_meta'] = [
            'passed' => $missingMeta->isEmpty(),
            'message' => $missingMeta->isEmpty()
                ? 'All wallet withdrawals carry ledger metadata'
                : "FAIL: {$missingMeta->count()} withdrawals missing ledger meta",
            'failing' => $missingMeta->pluck('wallet')->values()->toArray(),
        ];

        // CHECK 3: Every order must have a corresponding journal entry
        $missing = [];
        foreach ($orders as $order) {
            $hasEntry = $entries->contains(
                fn ($e) => str_contains($e['reference'] ?? '', (string) $order['order_id'])
            );
            if (! $hasEntry) {
                $missing[] = $order['order_id'];
            }
        }
        $checks['every_order_has_journal_entry'] = [
            'passed' => empty($missing),
            'message' => empty($missing)
                ? 'Every order has a corresponding journal entry'
                : 'FAIL: Orders without journal entries found',
            'failing' => $missing,
        ];

        // CHECK 4: Provider API success must be followed by a wallet deduction
        $apiSuccesses = $events->where('event_type', 'provider_api_success')->pluck('data');
        $unmatched = [];
        foreach ($apiSuccesses as $api) {
            $ref = $api['reference'];
            $hasTx = $txs->contains(
                fn ($tx) => ($tx['meta']['reference'] ?? null) === $ref && $tx['type'] === 'withdraw'
            );
            if (! $hasTx) {
                $unmatched[] = $ref;
            }
        }
        $checks['provider_success_has_wallet_deduction'] = [
            'passed' => empty($unmatched),
            'message' => empty($unmatched)
                ? 'All provider API successes have a wallet deduction'
                : 'FAIL: Provider API succeeded but no wallet deduction found',
            'failing' => $unmatched,
        ];

        // CHECK 5: Revenue accounts must be net of VAT
        $vatErrors = [];
        foreach ($orders as $order) {
            $expectedNet = round(($order['selling_price'] ?? 0) - ($order['vat_amount'] ?? 0), 3);
            $revenueEntry = $entries->first(
                fn ($e) => str_contains($e['reference'] ?? '', (string) $order['order_id'])
            );
            if ($revenueEntry) {
                $revenueLine = collect($revenueEntry['lines'])->first(
                    fn ($l) => in_array($l['account_code'] ?? '', ['4100', '4200', '4300', '4400'])
                );
                if ($revenueLine && round(abs(($revenueLine['credit'] ?? 0) - $expectedNet), 3) > 0.001) {
                    $vatErrors[] = [
                        'order_id' => $order['order_id'],
                        'expected_net' => $expectedNet,
                        'posted_net' => $revenueLine['credit'],
                    ];
                }
            }
        }
        $checks['revenue_is_net_of_vat'] = [
            'passed' => empty($vatErrors),
            'message' => empty($vatErrors)
                ? 'Revenue is correctly recorded net of VAT'
                : 'FAIL: Revenue not matching expected net-of-VAT amount',
            'failing' => $vatErrors,
        ];

        // CHECK 6: No negative gross margin
        $negativeMargin = $orders->filter(fn ($o) => ($o['gross_margin'] ?? 0) < 0);
        $checks['no_negative_gross_margin'] = [
            'passed' => $negativeMargin->isEmpty(),
            'message' => $negativeMargin->isEmpty()
                ? 'No negative gross margins detected'
                : "WARNING: {$negativeMargin->count()} orders have negative gross margin",
            'failing' => $negativeMargin->pluck('order_id')->values()->toArray(),
        ];

        // CHECK 7: No duplicate journal entry references
        $entryRefs = $entries->pluck('reference');
        $duplicates = $entryRefs->duplicates()->values()->toArray();
        $checks['no_duplicate_journal_entries'] = [
            'passed' => empty($duplicates),
            'message' => empty($duplicates)
                ? 'No duplicate journal entries found'
                : 'FAIL: Duplicate journal entries detected',
            'failing' => $duplicates,
        ];

        return $checks;
    }

    private function buildProviderApiSummary(Collection $events): array
    {
        return [
            'calls' => $events->where('event_type', 'provider_api_call')
                ->pluck('data')
                ->map(fn ($e) => ['provider' => $e['provider'], 'type' => $e['type']])
                ->toArray(),
            'successes' => $events->where('event_type', 'provider_api_success')
                ->pluck('data')
                ->map(fn ($e) => [
                    'provider' => $e['provider'],
                    'type' => $e['type'],
                    'reference' => $e['reference'],
                    'cost' => $e['cost'],
                ])
                ->toArray(),
            'failures' => $events->where('event_type', 'provider_api_failure')
                ->pluck('data')
                ->map(fn ($e) => [
                    'provider' => $e['provider'],
                    'type' => $e['type'],
                    'error' => $e['error'],
                ])
                ->toArray(),
        ];
    }

    private function detectAnomalies(Collection $events): array
    {
        $anomalies = [];

        // Anomaly 1: HTTP 5xx responses
        $errors = $events->where('event_type', 'http_response')
            ->filter(fn ($e) => ($e['data']['status'] ?? 200) >= 500);
        if ($errors->isNotEmpty()) {
            $anomalies[] = [
                'type' => 'SERVER_ERROR',
                'count' => $errors->count(),
                'detail' => $errors->pluck('data.status')->toArray(),
            ];
        }

        // Anomaly 2: Wallet deduction without matching order
        $walletTxs = $events->where('event_type', 'wallet_transaction')
            ->pluck('data')
            ->where('type', 'withdraw');
        $orderIds = $events->where('event_type', 'order_created')
            ->pluck('data')
            ->pluck('order_id')
            ->toArray();
        $orphaned = $walletTxs->filter(function (array $tx) use ($orderIds) {
            $orderId = $tx['meta']['order_id'] ?? null;

            return $orderId && ! in_array($orderId, $orderIds);
        });
        if ($orphaned->isNotEmpty()) {
            $anomalies[] = [
                'type' => 'ORPHANED_WALLET_DEDUCTION',
                'detail' => $orphaned->pluck('meta.order_id')->toArray(),
            ];
        }

        // Anomaly 3: Provider API success but no journal entry
        $apiRefs = $events->where('event_type', 'provider_api_success')
            ->pluck('data.reference')
            ->toArray();
        $entryRefs = $events->where('event_type', 'journal_entry_posted')
            ->pluck('data.reference')
            ->toArray();
        $noEntry = array_filter(
            $apiRefs,
            fn ($ref) => ! collect($entryRefs)->contains(fn ($e) => str_contains((string) $e, (string) $ref))
        );
        if (! empty($noEntry)) {
            $anomalies[] = [
                'type' => 'PROVIDER_SUCCESS_NO_LEDGER_ENTRY',
                'detail' => array_values($noEntry),
            ];
        }

        // Anomaly 4: Journal entries with zero debit total
        $zeroEntries = $events->where('event_type', 'journal_entry_posted')
            ->filter(fn ($e) => ($e['data']['total_debit'] ?? 0) == 0);
        if ($zeroEntries->isNotEmpty()) {
            $anomalies[] = [
                'type' => 'ZERO_AMOUNT_JOURNAL_ENTRY',
                'detail' => $zeroEntries->pluck('data.reference')->toArray(),
            ];
        }

        // Anomaly 5: Exceptions recorded during session
        $exceptions = $events->where('event_type', 'exception');
        if ($exceptions->isNotEmpty()) {
            $anomalies[] = [
                'type' => 'EXCEPTIONS_DURING_SESSION',
                'count' => $exceptions->count(),
                'detail' => $exceptions->pluck('data.message')->toArray(),
            ];
        }

        return $anomalies;
    }
}
