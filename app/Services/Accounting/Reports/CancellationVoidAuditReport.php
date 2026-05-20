<?php

namespace App\Services\Accounting\Reports;

use App\Models\Tenant\Order;
use Illuminate\Support\Collection;

/**
 * Audit report for cancelled orders and their reversal journal entries.
 *
 * Returns a collection of cancelled orders with their order number, amounts,
 * and cancellation fee (if any).
 */
class CancellationVoidAuditReport
{
    /**
     * Generate the cancellation audit trail.
     *
     * @return Collection<int, array{order_id: string, order_number: string, grand_total: float, amount_refunded: float, cancellation_fee: float, cancelled_at: string|null}>
     */
    public function generate(): Collection
    {
        return Order::where('status', 'cancelled')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (Order $order) {
                $grandTotal = (float) $order->grand_total;
                $amountRefunded = (float) $order->amount_refunded;

                return [
                    'order_id' => $order->id,
                    'order_number' => $order->number,
                    'grand_total' => $grandTotal,
                    'amount_refunded' => $amountRefunded,
                    'cancellation_fee' => round($grandTotal - $amountRefunded, 3),
                    'cancelled_at' => $order->updated_at?->toDateTimeString(),
                ];
            });
    }
}
