<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\Reports\CancellationVoidAuditReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CancellationAuditController extends Controller
{
    public function __construct(
        private readonly CancellationVoidAuditReport $report,
    ) {}

    public function index(Request $request): Response
    {
        $all = $this->report->generate();

        // Apply filters
        if ($request->filled('from')) {
            $all = $all->filter(fn ($row) => $row['cancelled_at'] >= $request->string('from')->toString());
        }
        if ($request->filled('to')) {
            $all = $all->filter(fn ($row) => $row['cancelled_at'] <= $request->string('to')->toString().' 23:59:59');
        }
        if ($request->filled('productType')) {
            // product_type not on Order directly — skip for now (no column)
        }

        // Manual pagination
        $page = (int) $request->get('page', 1);
        $perPage = 25;
        $total = $all->count();
        $items = $all->slice(($page - 1) * $perPage, $perPage)->values();

        // Map to UI shape
        $cancellations = $items->map(function (array $row) {
            return [
                'orderId' => $row['order_id'],
                'orderNumber' => $row['order_number'],
                'productType' => 'airline', // default — Order model has no product_type column
                'providerReference' => null,
                'originalSalePrice' => $row['grand_total'],
                'cancellationFee' => $row['cancellation_fee'],
                'netRefunded' => $row['amount_refunded'],
                'providerBalanceRestored' => false,
                'cancelledAt' => $row['cancelled_at'],
                'cancelledBy' => 'System',
                'reversalJournalReference' => null,
            ];
        })->all();

        return Inertia::render('Accounting/Cancellations/Index', [
            'cancellations' => [
                'data' => $cancellations,
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
            'filters' => $request->only(['from', 'to', 'productType']),
        ]);
    }
}
