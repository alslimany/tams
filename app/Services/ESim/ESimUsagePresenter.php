<?php

namespace App\Services\ESim;

use App\Models\Tenant\OrderItem;

class ESimUsagePresenter
{
    /**
     * @param  array<string, mixed>  $payload  L2 utilisation callback body
     * @return array<string, mixed>
     */
    public function fromWebhookPayload(array $payload): array
    {
        $bundle = is_array($payload['bundle'] ?? null) ? $payload['bundle'] : [];
        $initial = max(0, (int) ($bundle['initialQuantity'] ?? 0));
        $remaining = max(0, (int) ($bundle['remainingQuantity'] ?? 0));
        $used = max(0, $initial - $remaining);
        $unlimited = (bool) ($bundle['unlimited'] ?? false);
        $percentUsed = $initial > 0 ? round(($used / $initial) * 100, 2) : ($unlimited ? 0.0 : 100.0);

        return [
            'alert_type' => (string) ($payload['alertType'] ?? 'Utilisation'),
            'iccid' => (string) ($payload['iccid'] ?? ''),
            'initial_quantity' => $initial,
            'remaining_quantity' => $remaining,
            'used_quantity' => $used,
            'unit' => (string) ($bundle['unit'] ?? 'BYTES'),
            'initial_mb' => $this->bytesToMb($initial),
            'remaining_mb' => $this->bytesToMb($remaining),
            'used_mb' => $this->bytesToMb($used),
            'percent_used' => $percentUsed,
            'percent_remaining' => round(max(0, 100 - $percentUsed), 2),
            'unlimited' => $unlimited,
            'start_time' => $bundle['startTime'] ?? null,
            'end_time' => $bundle['endTime'] ?? null,
            'valid_until' => $bundle['endTime'] ?? null,
            'bundle_id' => isset($bundle['id']) ? (string) $bundle['id'] : null,
            'bundle_name' => (string) ($bundle['name'] ?? ''),
            'bundle_description' => (string) ($bundle['description'] ?? ''),
            'bundle_reference' => (string) ($bundle['reference'] ?? ''),
            'updated_at' => now()->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fromOrderItem(OrderItem $item): ?array
    {
        $usage = data_get($item->item_details, 'usage');

        return is_array($usage) && $usage !== [] ? $usage : null;
    }

    protected function bytesToMb(int $bytes): float
    {
        return round($bytes / 1024 / 1024, 2);
    }
}
