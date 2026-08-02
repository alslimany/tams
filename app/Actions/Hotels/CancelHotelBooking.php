<?php

namespace App\Actions\Hotels;

use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantHotelProvider;
use App\Services\Hotels\HotelApiException;
use App\Services\Hotels\HotelProviderManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CancelHotelBooking
{
    public function __construct(
        protected HotelProviderManager $providerManager,
    ) {}

    /**
     * Cancel a hotel order item with the active provider and credit the provider wallet on success.
     *
     * @return array{
     *     outcome: 'cancelled'|'cancellation_requested',
     *     message: string,
     *     order: Order,
     *     item: OrderItem,
     *     cancellation_fee: float|null,
     *     refund_amount: float|null,
     * }
     */
    public function execute(Order $order, OrderItem $item): array
    {
        if ($item->order_id !== $order->id || ! $this->isHotelItem($item)) {
            throw new RuntimeException('Hotel order item was not found on this order.');
        }

        if (in_array((string) $item->status, ['cancelled', 'refunded', 'cancellation'], true)) {
            throw new RuntimeException('This hotel booking cannot be cancelled in its current status.');
        }

        $hotelProvider = $this->providerManager->activeProvider();

        if (! $hotelProvider instanceof TenantHotelProvider) {
            throw new RuntimeException('Hotel provider is not configured.');
        }

        $bookingId = (string) data_get($item->item_details, 'booking_id', $item->provider_reference ?? '');

        if ($bookingId === '') {
            throw new RuntimeException('No provider booking id found for this hotel order item.');
        }

        try {
            $payload = $this->providerManager->provider()->cancel([
                'bookingId' => $bookingId,
                'bookingSource' => data_get($item->item_details, 'booking_source'),
            ]);
        } catch (HotelApiException $exception) {
            if ($this->isThreeTCancellationRequestCreated($exception)) {
                $this->markHotelCancellationRequested(
                    $order,
                    $item,
                    $exception->context()['response'] ?? [],
                    $exception->getMessage(),
                );

                return [
                    'outcome' => 'cancellation_requested',
                    'message' => 'Auto cancellation was denied by 3T, but a cancellation request has been sent for your booking.',
                    'order' => $order->fresh(),
                    'item' => $item->fresh(),
                    'cancellation_fee' => null,
                    'refund_amount' => null,
                ];
            }

            throw $exception;
        }

        $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];
        $canceled = (bool) ($response['canceled'] ?? false);

        if (! $canceled) {
            throw new RuntimeException((string) ($payload['message'] ?? 'Hotel cancellation was not confirmed by provider.'));
        }

        $cancellationFee = round((float) ($response['cancellationFee'] ?? 0), 2);
        $refundAmount = max(0.0, round((float) data_get($item->item_details, 'provider_cost', $item->net_fare ?? 0) - $cancellationFee, 2));

        DB::transaction(function () use ($order, $item, $hotelProvider, $payload, $cancellationFee, $refundAmount): void {
            $details = (array) $item->item_details;
            $details['cancellation'] = [
                'provider_response' => $payload,
                'cancellation_fee' => $cancellationFee,
                'refund_amount' => $refundAmount,
                'cancelled_at' => now()->toISOString(),
            ];

            if ($refundAmount > 0) {
                $wallet = $hotelProvider->getOrCreateCurrencyWallet((string) ($item->currency ?? $order->currency ?? 'USD'));
                $refund = $wallet->depositFloat($refundAmount, [
                    'type' => 'cancellation',
                    'provider_type' => 'hotel',
                    'hotel_provider_type' => $hotelProvider->provider_type,
                    'provider_id' => $hotelProvider->id,
                    'tenant_id' => tenant()?->id,
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'product_type' => 'hotel',
                    'provider_reference' => (string) $item->provider_reference,
                    'cancellation_fee' => $cancellationFee,
                ]);

                $details['cancellation']['provider_wallet_transaction_id'] = $refund->uuid;
            }

            $item->update([
                'status' => 'cancelled',
                'refund_status' => $refundAmount > 0 ? 'refunded' : 'none',
                'item_details' => $details,
            ]);

            $order->update([
                'status' => 'cancelled',
                'amount_refunded' => $refundAmount,
            ]);
        });

        return [
            'outcome' => 'cancelled',
            'message' => 'Hotel booking cancelled successfully.',
            'order' => $order->fresh(),
            'item' => $item->fresh(),
            'cancellation_fee' => $cancellationFee,
            'refund_amount' => $refundAmount,
        ];
    }

    protected function isHotelItem(OrderItem $item): bool
    {
        return (string) $item->product_type === 'hotel' || (string) $item->type === 'hotel';
    }

    protected function isThreeTCancellationRequestCreated(HotelApiException $exception): bool
    {
        $response = $exception->context()['response'] ?? [];
        $message = strtolower($exception->getMessage());

        return (string) ($response['method'] ?? '') === 'cancel'
            && (string) ($response['errorCode'] ?? '') === '502'
            && str_contains($message, 'cancellation request')
            && str_contains($message, 'sent');
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    protected function markHotelCancellationRequested(Order $order, OrderItem $item, array $providerResponse, string $message): void
    {
        DB::transaction(function () use ($order, $item, $providerResponse, $message): void {
            $details = (array) $item->item_details;
            $details['cancellation_request'] = [
                'status' => 'requested',
                'message' => $message,
                'provider_response' => $providerResponse,
                'requested_at' => now()->toISOString(),
                'auto_cancellation_denied' => true,
            ];

            $item->update([
                'status' => 'cancellation',
                'item_details' => $details,
            ]);

            $order->update(['status' => 'cancellation']);
        });
    }
}
