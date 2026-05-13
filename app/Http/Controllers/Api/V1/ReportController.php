<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Controller;
use App\Models\Tenant\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Daily sales summary.
     */
    public function sales(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $startDate = $validated['start_date'] ?? now()->subDays(30)->toDateString();
        $endDate = $validated['end_date'] ?? now()->toDateString();

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

        return $this->success([
            'rows' => $rows,
            'grand_totals' => $grandTotals,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    /**
     * Commission report.
     */
    public function commissions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $startDate = $validated['start_date'] ?? now()->subDays(30)->toDateString();
        $endDate = $validated['end_date'] ?? now()->toDateString();

        $items = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween(DB::raw('DATE(orders.issued_at)'), [$startDate, $endDate])
            ->whereNotIn('order_items.status', ['voided', 'refunded'])
            ->where('order_items.commission_amount', '>', 0)
            ->get([
                'order_items.id',
                'order_items.product_type',
                'order_items.provider_reference',
                'order_items.currency',
                'order_items.net_fare',
                'order_items.total_amount',
                'order_items.commission_amount',
                'order_items.net_after_commission',
                'order_items.agent_commission',
                'orders.number as order_number',
                'orders.issued_at',
            ]);

        return $this->success([
            'items' => $items,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }
}
