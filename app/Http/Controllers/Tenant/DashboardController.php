<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Order;
use App\Models\Tenant\Ticket;
use App\Models\TenantProvider;
use App\Models\User;
use Bavix\Wallet\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $startOfMonth = now()->startOfMonth();
        $startOfLastMonth = now()->subMonth()->startOfMonth();
        $endOfLastMonth = now()->subMonth()->endOfMonth();

        // Current month stats
        $currentMonthOrders = Order::query()->where('created_at', '>=', $startOfMonth);
        $currentMonthRevenue = (float) $currentMonthOrders->sum('grand_total');
        $currentMonthCount = $currentMonthOrders->count();

        // Last month stats for comparison
        $lastMonthOrders = Order::query()
            ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth]);
        $lastMonthRevenue = (float) $lastMonthOrders->sum('grand_total');
        $lastMonthCount = $lastMonthOrders->count();

        // Calculate growth percentage
        $revenueGrowth = $lastMonthRevenue > 0
            ? round((($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
            : 0;

        $ordersGrowth = $lastMonthCount > 0
            ? round((($currentMonthCount - $lastMonthCount) / $lastMonthCount) * 100, 1)
            : 0;

        // Get wallet transactions for the last 30 days
        $walletTransactions = Transaction::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function (Transaction $tx): array {
                return [
                    'id' => $tx->uuid,
                    'type' => $tx->type,
                    'amount' => $tx->amount,
                    'created_at' => $tx->created_at->toISOString(),
                ];
            });

        // Wallet balance
        $walletBalance = $this->resolveWalletBalance();

        // Ticket status breakdown
        $ticketStatus = Ticket::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Recent orders
        $recentBookings = Order::query()
            ->with(['items:id,order_id,provider_reference,status'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function (Order $order): array {
                return [
                    'id' => $order->id,
                    'pnr' => (string) ($order->items->first()?->provider_reference ?? $order->payment_reference),
                    'status' => $order->status,
                    'customer' => [
                        'first_name' => data_get($order->contact, 'first_name', data_get($order->owner, 'name', '')),
                        'last_name' => data_get($order->contact, 'last_name', ''),
                    ],
                    'total' => (float) $order->grand_total,
                    'currency' => $order->currency,
                    'created_at' => $order->created_at->toISOString(),
                ];
            })
            ->values();

        // Provider status
        $providerStatus = TenantProvider::query()
            ->latest()
            ->get(['id', 'airline_code', 'airline_name', 'account_name', 'is_active', 'last_tested_at', 'last_test_status']);

        // Top routes (based on recent orders)
        $topRoutes = $this->getTopRoutes();

        return Inertia::render('Tenant/Dashboard', [
            'stats' => [
                'todaysOrders' => Order::whereDate('created_at', today())->count(),
                'todaysRevenue' => (float) Order::whereDate('created_at', today())->sum('grand_total'),
                'monthlyOrders' => $currentMonthCount,
                'monthlyRevenue' => $currentMonthRevenue,
                'revenueGrowth' => $revenueGrowth,
                'ordersGrowth' => $ordersGrowth,
                'totalIssuedTickets' => Ticket::where('status', 'issued')->count(),
                'activeProviders' => TenantProvider::where('is_active', true)->count(),
                'totalCustomers' => User::where('is_active', true)->count(),
            ],
            'wallet' => $walletBalance,
            'ticketStatus' => $ticketStatus,
            'recentBookings' => $recentBookings,
            'providerStatus' => $providerStatus,
            'topRoutes' => $topRoutes,
            'walletTransactions' => $walletTransactions,
        ]);
    }

    protected function resolveWalletBalance(): array
    {
        $currencies = ['LYD', 'USD', 'EUR'];
        $balances = [];

        foreach ($currencies as $currency) {
            $transactions = Transaction::query()
                ->where('created_at', '>=', now()->subDays(30))
                ->where('currency', $currency)
                ->get();

            $deposits = $transactions->where('type', 'deposit')->sum('amount');
            $withdrawals = $transactions->where('type', 'withdraw')->sum('amount');

            $balances[$currency] = [
                'deposits' => $deposits / 100,
                'withdrawals' => $withdrawals / 100,
                'net' => ($deposits + $withdrawals) / 100,
            ];
        }

        return $balances;
    }

    protected function getTopRoutes(): Collection
    {
        $orders = Order::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->with(['items:id,order_id,item_details'])
            ->get();

        $routes = collect();

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $segments = data_get($item->item_details, 'segments', []);
                foreach ($segments as $segment) {
                    $origin = data_get($segment, 'departure_airport', '');
                    $destination = data_get($segment, 'arrival_airport', '');
                    if ($origin && $destination) {
                        $key = "{$origin}-{$destination}";
                        $routes->put($key, ($routes->get($key) ?? 0) + 1);
                    }
                }
            }
        }

        return $routes->sortDesc()->take(5)->map(fn ($count, $route) => [
            'route' => $route,
            'count' => $count,
        ])->values();
    }
}
