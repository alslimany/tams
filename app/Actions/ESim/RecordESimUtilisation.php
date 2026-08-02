<?php

namespace App\Actions\ESim;

use App\Models\Tenant\OrderItem;
use App\Services\ESim\ESimUsagePresenter;
use Illuminate\Support\Facades\DB;

class RecordESimUtilisation
{
    public function __construct(
        protected ESimUsagePresenter $usagePresenter,
    ) {}

    /**
     * Persist L2 utilisation callback data onto the matching eSIM order item.
     *
     * @param  array<string, mixed>  $payload
     */
    public function execute(array $payload): ?OrderItem
    {
        $iccid = (string) ($payload['iccid'] ?? '');

        if ($iccid === '') {
            return null;
        }

        $item = OrderItem::query()
            ->where(function ($query): void {
                $query->where('type', 'esim')
                    ->orWhere('product_type', 'esim');
            })
            ->where(function ($query) use ($iccid): void {
                $query->where('ticket_number', $iccid)
                    ->orWhere('item_details->iccid', $iccid);
            })
            ->orderByDesc('id')
            ->first();

        if (! $item instanceof OrderItem) {
            return null;
        }

        $usage = $this->usagePresenter->fromWebhookPayload($payload);

        return DB::transaction(function () use ($item, $usage, $payload): OrderItem {
            $details = (array) $item->item_details;
            $events = is_array($details['usage_events'] ?? null) ? $details['usage_events'] : [];
            $events[] = [
                'alert_type' => $usage['alert_type'],
                'remaining_quantity' => $usage['remaining_quantity'],
                'percent_used' => $usage['percent_used'],
                'received_at' => now()->toISOString(),
                'raw_alert_type' => $payload['alertType'] ?? null,
            ];
            $details['usage'] = $usage;
            $details['usage_events'] = array_slice($events, -20);
            $details['bundle_reference'] = $usage['bundle_reference'] ?: ($details['bundle_reference'] ?? null);

            $productDetails = (array) $item->product_details;
            $productDetails['usage'] = $usage;

            $item->update([
                'item_details' => $details,
                'product_details' => $productDetails,
            ]);

            return $item->fresh();
        });
    }
}
