<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Tenant\OrderItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IssuanceHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $query = OrderItem::with('order')
            ->whereHas('order', fn ($q) => $q->whereNotNull('issued_at'))
            ->orderByDesc('created_at');

        if ($request->filled('productType')) {
            $query->where('product_type', $request->string('productType'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('from')) {
            $query->whereHas('order', fn ($q) => $q->whereDate('issued_at', '>=', $request->string('from')));
        }

        if ($request->filled('to')) {
            $query->whereHas('order', fn ($q) => $q->whereDate('issued_at', '<=', $request->string('to')));
        }

        if ($request->filled('issuedBy')) {
            // owner_type/owner_id morph — filter by owner display name stored in contact
            $query->whereHas('order', function ($q) use ($request) {
                $q->where('contact->name', 'like', '%'.$request->string('issuedBy').'%');
            });
        }

        $paginated = $query->paginate(25)->through(function (OrderItem $item) {
            $order = $item->order;
            $sellingPrice = (float) ($item->total_amount ?? $item->total);
            $providerCost = (float) ($item->net_fare ?? 0);
            $vatAmount = (float) ($item->total_tax ?? $item->taxes ?? 0);
            $grossMargin = round($sellingPrice - $providerCost - $vatAmount, 3);
            $grossMarginPct = $sellingPrice > 0 ? round(($grossMargin / $sellingPrice) * 100, 2) : 0.0;

            $contact = $order?->contact ?? [];
            $issuedBy = is_array($contact) ? ($contact['name'] ?? 'Unknown') : 'Unknown';

            return [
                'id' => $item->id,
                'orderId' => $order?->id,
                'orderNumber' => $order?->number,
                'productType' => $item->product_type ?? $item->type,
                'providerReference' => $item->provider_reference ?? '',
                'sellingPrice' => $sellingPrice,
                'vatAmount' => $vatAmount,
                'providerCost' => $providerCost,
                'grossMargin' => $grossMargin,
                'grossMarginPct' => $grossMarginPct,
                'issuedAt' => $order?->issued_at?->toIso8601String(),
                'status' => $item->status,
                'journalReference' => $item->ledger_entry_id ? (string) $item->ledger_entry_id : null,
                'issuedBy' => $issuedBy,
            ];
        });

        // Summary KPIs (unfiltered for the current filter set — re-query aggregates)
        $summaryQuery = OrderItem::whereHas('order', fn ($q) => $q->whereNotNull('issued_at'));

        if ($request->filled('productType')) {
            $summaryQuery->where('product_type', $request->string('productType'));
        }
        if ($request->filled('status')) {
            $summaryQuery->where('status', $request->string('status'));
        }
        if ($request->filled('from')) {
            $summaryQuery->whereHas('order', fn ($q) => $q->whereDate('issued_at', '>=', $request->string('from')));
        }
        if ($request->filled('to')) {
            $summaryQuery->whereHas('order', fn ($q) => $q->whereDate('issued_at', '<=', $request->string('to')));
        }

        $summaryItems = $summaryQuery->get(['product_type', 'type', 'total_amount', 'total', 'net_fare', 'total_tax', 'taxes']);

        $totalRevenue = $summaryItems->sum(fn ($i) => (float) ($i->total_amount ?? $i->total));
        $totalCost = $summaryItems->sum(fn ($i) => (float) ($i->net_fare ?? 0));
        $totalVat = $summaryItems->sum(fn ($i) => (float) ($i->total_tax ?? $i->taxes ?? 0));
        $totalMargin = round($totalRevenue - $totalCost - $totalVat, 3);

        $countByProduct = $summaryItems
            ->groupBy(fn ($i) => $i->product_type ?? $i->type)
            ->map->count()
            ->all();

        return Inertia::render('Accounting/Issuance/History', [
            'issuances' => $paginated,
            'summary' => [
                'totalRevenue' => round($totalRevenue, 3),
                'totalCost' => round($totalCost, 3),
                'totalMargin' => $totalMargin,
                'countByProduct' => $countByProduct,
            ],
            'filters' => $request->only(['productType', 'status', 'from', 'to', 'issuedBy']),
        ]);
    }
}
