<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\OrderItem;
use App\Models\User;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FinanceReconcileCommand extends Command
{
    protected $signature = 'finance:reconcile
        {tenantId? : Optional tenant ID. If omitted, all tenants are processed}
        {--hours=24 : Look-back window in hours}
        {--currency= : Filter to a specific currency}';

    protected $description = 'Compare order totals with wallet/ledger for the last N hours and report discrepancies.';

    public function handle(): int
    {
        $tenantId = $this->argument('tenantId');
        $hours = (int) $this->option('hours');
        $currencyFilter = $this->option('currency');

        $tenants = Tenant::query()
            ->when(
                is_string($tenantId) && $tenantId !== '',
                fn ($query): \Illuminate\Database\Eloquent\Builder => $query->where('id', $tenantId),
            )
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->error('No tenants matched the provided criteria.');

            return self::FAILURE;
        }

        $hasDiscrepancies = false;

        foreach ($tenants as $tenant) {
            $this->line("Reconciling tenant: {$tenant->id}");

            $result = $tenant->run(function () use ($hours, $currencyFilter): array {
                return $this->reconcileTenant($hours, $currencyFilter);
            });

            if ($result['has_discrepancies']) {
                $hasDiscrepancies = true;
            }

            $this->renderResult($result);
        }

        return $hasDiscrepancies ? self::FAILURE : self::SUCCESS;
    }

    protected function reconcileTenant(int $hours, ?string $currencyFilter): array
    {
        $startDate = now()->subHours($hours)->toDateString();
        $endDate = now()->toDateString();

        // Order totals by currency.
        $orderTotals = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween(DB::raw('DATE(orders.issued_at)'), [$startDate, $endDate])
            ->whereNotIn('order_items.status', ['voided', 'refunded'])
            ->groupBy('order_items.currency')
            ->when($currencyFilter, fn ($q) => $q->having('order_items.currency', $currencyFilter))
            ->get([
                'order_items.currency',
                DB::raw('SUM(order_items.total_amount) as total_order_amount'),
                DB::raw('SUM(order_items.commission_amount) as total_commission'),
            ]);

        // Wallet transaction totals.
        $walletHolder = User::query()
            ->whereIn('role', ['admin', 'manager'])
            ->orderBy('id')
            ->first();

        $walletTotals = collect();

        if ($walletHolder) {
            $wallets = $walletHolder->wallets;
            $walletTotals = $wallets->mapWithKeys(function ($wallet) use ($startDate, $endDate, $currencyFilter): array {
                $currency = $wallet->meta['currency'] ?? $wallet->slug;

                if ($currencyFilter && $currency !== $currencyFilter) {
                    return [$currency => [
                        'currency' => $currency,
                        'total_deposits' => 0,
                        'total_withdrawals' => 0,
                    ]];
                }

                $deposits = WalletTransaction::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('type', 'deposit')
                    ->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate])
                    ->sum('amount');

                $withdrawals = WalletTransaction::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('type', 'withdraw')
                    ->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate])
                    ->sum('amount');

                return [$currency => [
                    'currency' => $currency,
                    'total_deposits' => (float) ($deposits / 100),
                    'total_withdrawals' => (float) ($withdrawals / 100),
                ]];
            });
        }

        // Airline account totals.
        $airlineAccountTotals = DB::table('airline_accounts')
            ->join('airline_transactions', 'airline_accounts.id', '=', 'airline_transactions.airline_account_id')
            ->whereBetween(DB::raw('DATE(airline_transactions.created_at)'), [$startDate, $endDate])
            ->when($currencyFilter, fn ($q) => $q->where('airline_accounts.currency', $currencyFilter))
            ->groupBy('airline_accounts.currency')
            ->get([
                'airline_accounts.currency',
                DB::raw('SUM(airline_transactions.amount) as total_airline_debits'),
            ]);

        // Build reconciliation rows.
        $currencies = $orderTotals->pluck('currency')
            ->merge($walletTotals->keys())
            ->merge($airlineAccountTotals->pluck('currency'))
            ->unique()
            ->sort()
            ->values();

        $rows = [];
        $hasDiscrepancies = false;

        foreach ($currencies as $currency) {
            $orderRow = $orderTotals->firstWhere('currency', $currency);
            $walletRow = $walletTotals->get($currency);
            $airlineRow = $airlineAccountTotals->firstWhere('currency', $currency);

            $orderAmount = (float) ($orderRow?->total_order_amount ?? 0);
            $orderCommission = (float) ($orderRow?->total_commission ?? 0);
            $walletWithdrawals = (float) ($walletRow?->total_withdrawals ?? 0);
            $airlineDebits = (float) ($airlineRow?->total_airline_debits ?? 0);

            $expectedOutflow = $orderAmount - $orderCommission;
            $actualOutflow = $walletWithdrawals + abs($airlineDebits);
            $difference = round($actualOutflow - $expectedOutflow, 2);

            $isBalanced = abs($difference) < 0.01;

            if (! $isBalanced) {
                $hasDiscrepancies = true;
            }

            $rows[] = [
                'currency' => $currency,
                'order_amount' => round($orderAmount, 2),
                'order_commission' => round($orderCommission, 2),
                'expected_outflow' => round($expectedOutflow, 2),
                'wallet_withdrawals' => round($walletWithdrawals, 2),
                'airline_debits' => round(abs($airlineDebits), 2),
                'actual_outflow' => round($actualOutflow, 2),
                'difference' => $difference,
                'is_balanced' => $isBalanced,
            ];
        }

        return [
            'has_discrepancies' => $hasDiscrepancies,
            'rows' => $rows,
            'hours' => $hours,
        ];
    }

    protected function renderResult(array $result): void
    {
        $rows = $result['rows'];
        $hours = $result['hours'];

        if (empty($rows)) {
            $this->info("  No financial activity in the last {$hours} hours.");

            return;
        }

        foreach ($rows as $row) {
            $status = $row['is_balanced'] ? '✓ BALANCED' : '✗ DISCREPANCY';
            $color = $row['is_balanced'] ? 'info' : 'error';

            $this->$color(
                sprintf(
                    '  %s %s: orders=%.2f commission=%.2f expected=%.2f | wallet_out=%.2f airline_out=%.2f actual=%.2f | diff=%.2f %s',
                    $row['currency'],
                    $row['is_balanced'] ? '✓' : '✗',
                    $row['order_amount'],
                    $row['order_commission'],
                    $row['expected_outflow'],
                    $row['wallet_withdrawals'],
                    $row['airline_debits'],
                    $row['actual_outflow'],
                    $row['difference'],
                    $status,
                )
            );
        }
    }
}
