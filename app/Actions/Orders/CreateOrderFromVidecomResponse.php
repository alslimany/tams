<?php

namespace App\Actions\Orders;

use App\DTOs\Videcom\OrderItemData;
use App\DTOs\Videcom\ParsedBookingData;
use App\Models\Tenant\Booking;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderStatusLog;
use App\Models\User;
use App\Services\Orders\OrderNumberGenerator;
use Illuminate\Support\Facades\DB;

class CreateOrderFromVidecomResponse
{
    public function __construct(
        protected OrderNumberGenerator $orderNumberGenerator,
        protected ProcessFinancialTransactions $processFinancialTransactions,
    ) {}

    public function execute(ParsedBookingData $parsedData, Booking $booking, User $user): Order
    {
        return DB::transaction(function () use ($parsedData, $booking, $user): Order {
            $subtotal = (float) collect($parsedData->items)->sum(fn (OrderItemData $item): float => $item->fare);
            $taxTotal = (float) collect($parsedData->items)->sum(fn (OrderItemData $item): float => $item->taxes);
            $grandTotal = (float) collect($parsedData->items)->sum(fn (OrderItemData $item): float => $item->total);

            $order = Order::query()->create([
                'owner_type' => User::class,
                'owner_id' => $user->id,
                'number' => $this->orderNumberGenerator->generate(),
                'status' => 'issued',
                'issued_at' => now(),
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,
                'amount_paid' => $grandTotal,
                'currency' => $parsedData->currency,
                'payment_method' => $parsedData->paymentMethod,
                'payment_reference' => $parsedData->paymentReference,
                'contact' => [
                    'first_name' => $booking->customer?->first_name,
                    'last_name' => $booking->customer?->last_name,
                    'email' => $booking->customer?->email,
                    'phone' => $booking->customer?->phone,
                ],
            ]);

            foreach ($parsedData->items as $itemData) {
                $order->items()->create([
                    'type' => 'flight',
                    'product_subtype' => 'oneway',
                    'provider' => 'videcom',
                    'provider_reference' => $parsedData->pnr,
                    'ticket_number' => $itemData->ticketNumber,
                    'item_details' => [
                        'pnr' => $parsedData->pnr,
                        'passenger_name' => $itemData->passengerName,
                        'segments' => $itemData->segments,
                        'airline_code' => $itemData->airlineCode,
                    ],
                    'price' => $itemData->fare,
                    'taxes' => $itemData->taxes,
                    'total' => $itemData->total,
                    'currency' => $itemData->currency,
                    'status' => 'issued',
                    'agent_commission' => $itemData->commission,
                    'paid' => $itemData->total,
                    'remaining' => 0,
                ]);
            }

            $this->processFinancialTransactions->execute($order, $booking, $user);

            OrderStatusLog::query()->create([
                'order_id' => $order->id,
                'old_status' => null,
                'new_status' => $order->status,
                'user_id' => $user->id,
                'comment' => 'Order created and ticket issued',
            ]);

            return $order->fresh(['items', 'statusLogs']) ?? $order;
        });
    }
}
