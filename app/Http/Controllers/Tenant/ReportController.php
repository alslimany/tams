<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantHotelProvider;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\TenantProvider;
use App\Models\User;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /**
     * Daily Sales Summary — grouped by date and product_type.
     */
    public function dailySales(Request $request): Response
    {
        $startDate = $request->string('start_date', now()->subDays(30)->toDateString())->toString();
        $endDate = $request->string('end_date', now()->toDateString())->toString();

        $rows = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween(DB::raw('DATE(orders.issued_at)'), [$startDate, $endDate])
            ->whereNotIn('order_items.status', ['voided', 'refunded'])
            ->groupBy('date', 'order_items.product_type', 'order_items.currency')
            ->orderByDesc('date')
            ->get([
                DB::raw('DATE(orders.issued_at) as date'),
                'order_items.product_type',
                'order_items.currency',
                DB::raw('COUNT(DISTINCT order_items.order_id) as order_count'),
                DB::raw('SUM(order_items.total_amount) as total_sales'),
                DB::raw('SUM(order_items.net_fare) as total_fare'),
                DB::raw('SUM(order_items.total_tax) as total_tax'),
                DB::raw('SUM(order_items.commission_amount) as total_commission'),
            ]);

        $grandTotals = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween(DB::raw('DATE(orders.issued_at)'), [$startDate, $endDate])
            ->whereNotIn('order_items.status', ['voided', 'refunded'])
            ->groupBy('order_items.currency')
            ->get([
                'order_items.currency',
                DB::raw('COUNT(DISTINCT order_items.order_id) as order_count'),
                DB::raw('SUM(order_items.total_amount) as total_sales'),
                DB::raw('SUM(order_items.net_fare) as total_fare'),
                DB::raw('SUM(order_items.total_tax) as total_tax'),
                DB::raw('SUM(order_items.commission_amount) as total_commission'),
            ]);

        return Inertia::render('Tenant/Reports/DailySales', [
            'rows' => $rows,
            'grandTotals' => $grandTotals,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    /**
     * Commission Report — order items with commission breakdown and supply type.
     */
    public function commissions(Request $request): Response
    {
        $startDate = $request->string('start_date', now()->subDays(30)->toDateString())->toString();
        $endDate = $request->string('end_date', now()->toDateString())->toString();

        $items = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween(DB::raw('DATE(orders.issued_at)'), [$startDate, $endDate])
            ->whereNotIn('order_items.status', ['voided', 'refunded'])
            ->where('order_items.commission_amount', '>', 0)
            ->select([
                'order_items.id',
                'order_items.order_id',
                'orders.number as order_number',
                'order_items.product_type',
                'order_items.provider_reference',
                'order_items.currency',
                'order_items.net_fare',
                'order_items.total_amount',
                'order_items.commission_percent',
                'order_items.commission_amount',
                'order_items.net_after_commission',
                'order_items.agent_commission',
                'order_items.net_commission',
                'order_items.item_details',
                'orders.issued_at',
            ])
            ->orderByDesc('orders.issued_at')
            ->paginate(25)
            ->withQueryString();

        // Fetch commission items and aggregate supply_type in PHP
        // (avoids MySQL-specific JSON functions that break SQLite in tests).
        $commissionItems = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween(DB::raw('DATE(orders.issued_at)'), [$startDate, $endDate])
            ->whereNotIn('order_items.status', ['voided', 'refunded'])
            ->where('order_items.commission_amount', '>', 0)
            ->get([
                'order_items.currency',
                'order_items.commission_amount',
                'order_items.net_after_commission',
                'order_items.item_details',
            ]);

        $summary = $commissionItems
            ->groupBy(fn (OrderItem $item): string => $item->currency.'_'.data_get($item->item_details, 'financial_source', 'unknown'))
            ->map(fn (\Illuminate\Support\Collection $group): array => [
                'currency' => $group->first()->currency,
                'supply_type' => data_get($group->first()->item_details, 'financial_source', 'unknown'),
                'total_commission' => $group->sum('commission_amount'),
                'total_net' => $group->sum('net_after_commission'),
                'item_count' => $group->count(),
            ])
            ->values();

        return Inertia::render('Tenant/Reports/Commissions', [
            'items' => $items,
            'summary' => $summary,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    /**
     * Tax Breakdown Report — parse taxes JSON and aggregate by tax code.
     */
    public function taxes(Request $request): Response
    {
        $startDate = $request->string('start_date', now()->subDays(30)->toDateString())->toString();
        $endDate = $request->string('end_date', now()->toDateString())->toString();

        $items = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween(DB::raw('DATE(orders.issued_at)'), [$startDate, $endDate])
            ->whereNotIn('order_items.status', ['voided', 'refunded'])
            ->whereNotNull('order_items.taxes')
            ->select([
                'order_items.id',
                'order_items.order_id',
                'orders.number as order_number',
                'order_items.provider_reference',
                'order_items.currency',
                'order_items.total_amount',
                'order_items.total_tax',
                'order_items.taxes',
                'orders.issued_at',
            ])
            ->orderByDesc('orders.issued_at')
            ->paginate(25)
            ->withQueryString();

        // Aggregate tax amounts by code across all matching items.
        $allTaxItems = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween(DB::raw('DATE(orders.issued_at)'), [$startDate, $endDate])
            ->whereNotIn('order_items.status', ['voided', 'refunded'])
            ->whereNotNull('order_items.taxes')
            ->get(['order_items.taxes', 'order_items.currency']);

        $taxBreakdown = [];
        foreach ($allTaxItems as $item) {
            $taxes = is_array($item->taxes) ? $item->taxes : json_decode((string) $item->taxes, true);
            if (! is_array($taxes)) {
                continue;
            }

            foreach ($taxes as $tax) {
                if (! is_array($tax)) {
                    continue;
                }

                $code = strtoupper(trim((string) ($tax['code'] ?? $tax['type'] ?? 'GEN')));
                $amount = (float) ($tax['amount'] ?? 0);
                $currency = (string) $item->currency;
                $key = "{$code}_{$currency}";

                if (! isset($taxBreakdown[$key])) {
                    $taxBreakdown[$key] = [
                        'code' => $code,
                        'currency' => $currency,
                        'total_amount' => 0.0,
                        'count' => 0,
                    ];
                }

                $taxBreakdown[$key]['total_amount'] += $amount;
                $taxBreakdown[$key]['count']++;
            }
        }

        $taxBreakdown = collect($taxBreakdown)->sortByDesc('total_amount')->values();

        return Inertia::render('Tenant/Reports/Taxes', [
            'items' => $items,
            'taxBreakdown' => $taxBreakdown,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    /**
     * Wallet Transaction History — list wallet transactions with filters.
     */
    public function walletTransactions(Request $request): Response
    {
        $startDate = $request->string('start_date', now()->subDays(30)->toDateString())->toString();
        $endDate = $request->string('end_date', now()->toDateString())->toString();
        $type = $request->string('type', '')->toString();

        $wallets = $request->user()->wallets
            ->merge(TenantProvider::query()->get()->flatMap->wallets)
            ->merge(TenantInsuranceProvider::query()->get()->flatMap->wallets)
            ->merge(TenantHotelProvider::query()->get()->flatMap->wallets)
            ->unique('id')
            ->values();

        $walletIds = $wallets->pluck('id')->all();

        $query = WalletTransaction::query()
            ->whereIn('wallet_id', $walletIds)
            ->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate]);

        if ($type !== '' && in_array($type, ['deposit', 'withdraw'], true)) {
            $query->where('type', $type);
        }

        $transactions = $query->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $walletMap = $wallets->keyBy('id');
        $holderMap = $wallets
            ->mapWithKeys(fn ($wallet): array => [
                $wallet->id => [
                    'type' => $wallet->holder_type,
                    'id' => $wallet->holder_id,
                ],
            ]);

        $transactions->through(function (WalletTransaction $transaction) use ($walletMap, $holderMap): array {
            $wallet = $walletMap->get($transaction->wallet_id);
            $meta = is_array($transaction->meta) ? $transaction->meta : [];
            $holder = $holderMap->get($transaction->wallet_id, []);
            $holderType = $this->walletHolderLabel((string) ($holder['type'] ?? ''));

            return [
                'id' => $transaction->id,
                'uuid' => $transaction->uuid,
                'type' => $transaction->type,
                'amount' => (float) ($transaction->amount / 100),
                'currency' => $wallet?->meta['currency'] ?? $wallet?->slug ?? 'USD',
                'wallet_holder_type' => $holderType,
                'wallet_holder_name' => $this->walletHolderName((string) ($holder['type'] ?? ''), $holder['id'] ?? null),
                'meta' => $meta,
                'order_id' => $meta['order_id'] ?? null,
                'description' => $meta['description'] ?? $meta['type'] ?? '',
                'created_at' => $transaction->created_at?->toIso8601String(),
            ];
        });

        $balanceSummary = $wallets->map(fn ($wallet) => [
            'slug' => $wallet->slug,
            'currency' => $wallet->meta['currency'] ?? $wallet->slug,
            'holder_type' => $this->walletHolderLabel((string) $wallet->holder_type),
            'holder_name' => $this->walletHolderName((string) $wallet->holder_type, $wallet->holder_id),
            'balance' => (float) ($wallet->balance / 100),
        ])->values();

        return Inertia::render('Tenant/Reports/WalletTransactions', [
            'transactions' => $transactions,
            'balanceSummary' => $balanceSummary,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'type' => $type,
            ],
        ]);
    }

    protected function walletHolderLabel(string $holderType): string
    {
        return match ($holderType) {
            User::class => 'Tenant Wallet',
            TenantProvider::class => 'Airline Provider Wallet',
            TenantInsuranceProvider::class => 'Insurance Provider Wallet',
            TenantHotelProvider::class => 'Hotel Provider Wallet',
            default => 'Wallet',
        };
    }

    protected function walletHolderName(string $holderType, mixed $holderId): string
    {
        if (! is_numeric($holderId)) {
            return $this->walletHolderLabel($holderType);
        }

        return match ($holderType) {
            User::class => (string) (User::query()->find((int) $holderId)?->name ?? 'Tenant Wallet'),
            TenantProvider::class => (string) (TenantProvider::query()->find((int) $holderId)?->airline_name ?? 'Airline Provider'),
            TenantInsuranceProvider::class => (string) (TenantInsuranceProvider::query()->find((int) $holderId)?->name ?? 'Insurance Provider'),
            TenantHotelProvider::class => (string) (TenantHotelProvider::query()->find((int) $holderId)?->name ?? 'Hotel Provider'),
            default => 'Wallet',
        };
    }

    /**
     * Reconciliation Report (admin only) — compare order totals against wallet and ledger.
     */
    public function reconciliation(Request $request): Response
    {
        $startDate = $request->string('start_date', now()->subDays(30)->toDateString())->toString();
        $endDate = $request->string('end_date', now()->toDateString())->toString();

        // Order totals by currency.
        $orderTotals = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween(DB::raw('DATE(orders.issued_at)'), [$startDate, $endDate])
            ->whereNotIn('order_items.status', ['voided', 'refunded'])
            ->groupBy('order_items.currency')
            ->get([
                'order_items.currency',
                DB::raw('SUM(order_items.total_amount) as total_order_amount'),
                DB::raw('SUM(order_items.commission_amount) as total_commission'),
            ]);

        // Wallet transaction totals by currency.
        $wallets = $request->user()->wallets;
        $walletMap = $wallets->keyBy(fn ($wallet) => $wallet->meta['currency'] ?? $wallet->slug);

        $walletTotals = $wallets->mapWithKeys(function ($wallet) use ($startDate, $endDate): array {
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

            $currency = $wallet->meta['currency'] ?? $wallet->slug;

            return [$currency => [
                'currency' => $currency,
                'total_deposits' => (float) ($deposits / 100),
                'total_withdrawals' => (float) ($withdrawals / 100),
                'current_balance' => (float) ($wallet->balance / 100),
            ]];
        });

        // Provider (airline own-credentials) debits from bavix wallets.
        $airlineAccountTotals = DB::table('wallets')
            ->join('transactions', 'wallets.id', '=', 'transactions.wallet_id')
            ->where('wallets.holder_type', TenantProvider::class)
            ->where('transactions.type', 'withdraw')
            ->whereBetween(DB::raw('DATE(transactions.created_at)'), [$startDate, $endDate])
            ->groupBy('wallets.slug')
            ->get([
                DB::raw('REPLACE(wallets.slug, "AIR_", "") as currency'),
                DB::raw('SUM(transactions.amount) / 100.0 as total_airline_debits'),
                DB::raw('COUNT(transactions.id) as transaction_count'),
            ]);

        // Build reconciliation rows by currency.
        $currencies = $orderTotals->pluck('currency')
            ->merge($walletTotals->keys())
            ->merge($airlineAccountTotals->pluck('currency'))
            ->unique()
            ->sort()
            ->values();

        $reconciliationRows = $currencies->map(function (string $currency) use ($orderTotals, $walletTotals, $airlineAccountTotals): array {
            $orderRow = $orderTotals->firstWhere('currency', $currency);
            $walletRow = $walletTotals->get($currency);
            $airlineRow = $airlineAccountTotals->firstWhere('currency', $currency);

            $orderAmount = (float) ($orderRow?->total_order_amount ?? 0);
            $orderCommission = (float) ($orderRow?->total_commission ?? 0);
            $walletWithdrawals = (float) ($walletRow?->total_withdrawals ?? 0);
            $walletDeposits = (float) ($walletRow?->total_deposits ?? 0);
            $airlineDebits = (float) ($airlineRow?->total_airline_debits ?? 0);

            $expectedOutflow = $orderAmount - $orderCommission;
            $actualOutflow = $walletWithdrawals + abs($airlineDebits);
            $difference = round($actualOutflow - $expectedOutflow, 2);

            return [
                'currency' => $currency,
                'order_amount' => round($orderAmount, 2),
                'order_commission' => round($orderCommission, 2),
                'expected_outflow' => round($expectedOutflow, 2),
                'wallet_withdrawals' => round($walletWithdrawals, 2),
                'wallet_deposits' => round($walletDeposits, 2),
                'airline_debits' => round(abs($airlineDebits), 2),
                'actual_outflow' => round($actualOutflow, 2),
                'difference' => $difference,
                'is_balanced' => $difference === 0.0,
            ];
        });

        return Inertia::render('Tenant/Reports/Reconciliation', [
            'reconciliationRows' => $reconciliationRows,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }
}
