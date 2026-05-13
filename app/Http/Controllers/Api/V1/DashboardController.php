<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Controller;
use App\Models\Tenant\Order;
use App\Models\Tenant\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $startOfMonth = now()->startOfMonth();
        $startOfLastMonth = now()->subMonth()->startOfMonth();
        $endOfLastMonth = now()->subMonth()->endOfMonth();

        $currentMonthOrders = Order::query()->where('created_at', '>=', $startOfMonth);
        $currentMonthRevenue = (float) $currentMonthOrders->sum('grand_total');
        $currentMonthCount = $currentMonthOrders->count();

        $lastMonthOrders = Order::query()->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth]);
        $lastMonthRevenue = (float) $lastMonthOrders->sum('grand_total');
        $lastMonthCount = $lastMonthOrders->count();

        $revenueGrowth = $lastMonthRevenue > 0
            ? round((($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
            : 0;

        $ordersGrowth = $lastMonthCount > 0
            ? round((($currentMonthCount - $lastMonthCount) / $lastMonthCount) * 100, 1)
            : 0;

        $ticketStatus = Ticket::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $recentBookings = Order::query()
            ->with(['items:id,order_id,provider_reference,status'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function (Order $order): array {
                return [
                    'id' => $order->id,
                    'number' => $order->number,
                    'pnr' => (string) ($order->items->first()?->provider_reference ?? $order->payment_reference),
                    'status' => $order->status,
                    'total' => (float) $order->grand_total,
                    'currency' => $order->currency,
                    'created_at' => $order->created_at->toISOString(),
                ];
            });

        return $this->success([
            'current_month' => [
                'revenue' => $currentMonthRevenue,
                'orders_count' => $currentMonthCount,
            ],
            'growth' => [
                'revenue_percent' => $revenueGrowth,
                'orders_percent' => $ordersGrowth,
            ],
            'ticket_status' => $ticketStatus,
            'recent_bookings' => $recentBookings,
        ]);
    }
}
