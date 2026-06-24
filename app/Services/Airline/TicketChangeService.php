<?php

namespace App\Services\Airline;

use App\Http\Controllers\Tenant\TicketController;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

class TicketChangeService
{
    public function __construct(
        private readonly TicketController $ticketController,
    ) {}

    /**
     * @return array{outstanding_amount: float, currency: string, change_type: string, raw_response?: string}
     */
    public function quote(
        Order $booking,
        OrderItem $item,
        int $segmentLine,
        string $newSegmentCode,
    ): array {
        $pnr = $this->resolvePnr($booking, $item);
        $providerConfig = $this->resolveProvider($item);

        if ($providerConfig === null) {
            throw new \RuntimeException('No active provider found for this booking.');
        }

        $provider = ProviderFactory::make($providerConfig);

        try {
            $quote = $provider->changeQuote($pnr, $segmentLine, $newSegmentCode);
        } catch (ConnectionException $exception) {
            throw new \RuntimeException('Airline change quote request timed out. Please try again.', 503, $exception);
        }

        $originalSegment = $this->resolveOriginalSegment($item, $segmentLine);
        $changeType = $this->determineChangeType($newSegmentCode, $originalSegment);

        return array_merge($quote, ['change_type' => $changeType]);
    }

    /**
     * @return array{success: bool, change_type: string, order: Order, item: OrderItem}
     */
    public function confirm(
        Order $booking,
        OrderItem $item,
        int $segmentLine,
        string $newSegmentCode,
        float $outstandingAmount = 0.0,
    ): array {
        $pnr = $this->resolvePnr($booking, $item);
        $providerConfig = $this->resolveProvider($item);

        if ($providerConfig === null) {
            throw new \RuntimeException('No active provider found for this booking.');
        }

        $provider = ProviderFactory::make($providerConfig);
        $originalSegment = $this->resolveOriginalSegment($item, $segmentLine);
        $changeType = $this->determineChangeType($newSegmentCode, $originalSegment);

        try {
            $result = $provider->confirmChange($pnr, $segmentLine, $newSegmentCode, $changeType, $outstandingAmount);
        } catch (ConnectionException $exception) {
            throw new \RuntimeException('Airline change request timed out. Please try again.', 503, $exception);
        }

        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException(
                'Airline rejected the change request. Response: '.($result['raw_response'] ?? 'unknown'),
                422,
            );
        }

        DB::transaction(function () use ($item, $booking, $segmentLine, $newSegmentCode, $changeType, $result, $outstandingAmount): void {
            $itemDetails = (array) $item->item_details;
            data_set($itemDetails, 'change.segment_line', $segmentLine);
            data_set($itemDetails, 'change.new_segment_code', $newSegmentCode);
            data_set($itemDetails, 'change.change_type', $changeType);
            data_set($itemDetails, 'change.outstanding_amount', $outstandingAmount);
            data_set($itemDetails, 'change.raw_response', $result['raw_response'] ?? '');
            data_set($itemDetails, 'change.changed_at', now()->toIso8601String());

            $item->update([
                'status' => 'changed',
                'item_details' => $itemDetails,
            ]);

            $booking->update(['status' => 'changed']);
        });

        return [
            'success' => true,
            'change_type' => $changeType,
            'order' => $booking->fresh(),
            'item' => $item->fresh(),
        ];
    }

    public function resolveProvider(OrderItem $item): ?TenantProvider
    {
        return $this->ticketController->resolveProviderForTicketActionPublic($item);
    }

    protected function resolvePnr(Order $booking, OrderItem $item): string
    {
        $pnr = (string) ($item->provider_reference ?: $booking->payment_reference);

        if ($pnr === '') {
            throw new \RuntimeException('PNR reference not found for this order item.');
        }

        return $pnr;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveOriginalSegment(OrderItem $item, int $segmentLine): ?array
    {
        $originalSegments = (array) data_get($item->item_details, 'itineraries',
            data_get($item->item_details, 'segments', [])
        );

        return collect($originalSegments)->firstWhere('line', $segmentLine)
            ?? collect($originalSegments)->firstWhere('itinerary_id', $segmentLine)
            ?? ($originalSegments[$segmentLine - 1] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $originalSegment
     */
    protected function determineChangeType(string $newSegmentCode, ?array $originalSegment): string
    {
        if (! $originalSegment) {
            return 'reissue';
        }

        if (preg_match('/^\d+([A-Z]{2})(\d{4})([A-Z])(\d{2}[A-Z]{3})([A-Z]{3})([A-Z]{3})/i', $newSegmentCode, $matches) !== 1) {
            return 'reissue';
        }

        $newClass = strtoupper($matches[3]);
        $newOrigin = strtoupper($matches[5]);
        $newDest = strtoupper($matches[6]);

        $origClass = strtoupper((string) ($originalSegment['class'] ?? ''));
        $origOrigin = strtoupper((string) ($originalSegment['departure_airport'] ?? $originalSegment['from'] ?? ''));
        $origDest = strtoupper((string) ($originalSegment['arrival_airport'] ?? $originalSegment['to'] ?? ''));

        if ($newOrigin === $origOrigin && $newDest === $origDest && $newClass === $origClass) {
            return 'revalidation';
        }

        return 'reissue';
    }
}
