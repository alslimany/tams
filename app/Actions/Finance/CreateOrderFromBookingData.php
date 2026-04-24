<?php

namespace App\Actions\Finance;

use App\DTOs\Videcom\OrderItemData;
use App\DTOs\Videcom\ParsedBookingData;
use App\Models\Tenant\Order;
use App\Models\User;
use App\Services\Orders\OrderNumberGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateOrderFromBookingData
{
    public function __construct(
        protected OrderNumberGenerator $orderNumberGenerator,
    ) {}

    public function execute(ParsedBookingData $bookingData): Order
    {
        $issuer = Auth::user();

        if (! $issuer instanceof User) {
            throw new RuntimeException('An authenticated user is required to create an order from booking data.');
        }

        return DB::transaction(function () use ($bookingData, $issuer): Order {
            $order = Order::query()->create([
                'owner_type' => $issuer::class,
                'owner_id' => $issuer->id,
                'number' => $this->generateUniqueOrderNumber(),
                'status' => 'issued',
                'issued_at' => now(),
                'subtotal' => 0,
                'tax_total' => 0,
                'grand_total' => 0,
                'amount_paid' => 0,
                'currency' => strtoupper($bookingData->currency),
                'payment_method' => $bookingData->paymentMethod,
                'payment_reference' => $bookingData->paymentReference ?: $bookingData->pnr,
                'contact' => null,
            ]);

            $subtotal = 0.0;
            $taxTotal = 0.0;
            $grandTotal = 0.0;

            foreach ($bookingData->items as $item) {
                $segmentCount = max(count($item->segments), 1);
                $fareParts = $this->splitAmount($item->fare, $segmentCount);
                $taxParts = $this->splitAmount($item->taxes, $segmentCount);
                $totalParts = $this->splitAmount($item->total, $segmentCount);

                foreach (array_values($item->segments ?: [[]]) as $index => $segment) {
                    $fare = $fareParts[$index] ?? 0.0;
                    $tax = $taxParts[$index] ?? 0.0;
                    $total = $totalParts[$index] ?? 0.0;

                    $order->items()->create([
                        'type' => 'flight',
                        'product_type' => 'ticket',
                        'product_subtype' => 'segment',
                        'provider' => 'videcom',
                        'provider_reference' => $bookingData->pnr,
                        'ticket_number' => $item->ticketNumber,
                        'item_details' => $this->buildItemDetails($bookingData, $item, $segment, $segmentCount),
                        'product_details' => $this->buildProductDetails($item, $segment),
                        'price' => $fare,
                        'net_fare' => $fare,
                        'taxes' => [
                            [
                                'type' => 'total',
                                'amount' => $tax,
                                'currency' => strtoupper($item->currency),
                            ],
                        ],
                        'total_tax' => $tax,
                        'total' => $total,
                        'total_amount' => $total,
                        'currency' => strtoupper($item->currency),
                        'exchange_rate' => 1,
                        'status' => 'issued',
                        'transaction_type' => 'issue',
                        'commission_percent' => 0,
                        'commission_amount' => 0,
                        'net_after_commission' => $fare,
                        'agent_commission' => 0,
                        'net_commission' => 0,
                        'paid' => $total,
                        'remaining' => 0,
                    ]);

                    $subtotal += $fare;
                    $taxTotal += $tax;
                    $grandTotal += $total;
                }
            }

            $order->update([
                'subtotal' => round($subtotal, 2),
                'tax_total' => round($taxTotal, 2),
                'grand_total' => round($grandTotal, 2),
                'amount_paid' => round($grandTotal, 2),
            ]);

            return $order->fresh('items');
        });
    }

    protected function generateUniqueOrderNumber(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $number = $this->normalizeOrderNumber($this->orderNumberGenerator->generate());

            if (! Order::query()->where('number', $number)->exists()) {
                return $number;
            }
        }

        throw new RuntimeException('Unable to generate a unique order number.');
    }

    protected function normalizeOrderNumber(string $raw): string
    {
        $prefix = $this->normalizeLetterPart(substr($raw, 0, 3), 3);
        $sequence = preg_match('/(\d{4})/', $raw, $matches) === 1
            ? $matches[1]
            : str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $suffix = $this->normalizeLetterPart(substr($raw, -2), 2);

        return $prefix.$sequence.$suffix;
    }

    protected function normalizeLetterPart(string $value, int $length): string
    {
        $lettersOnly = preg_replace('/[^A-Z]/', '', strtoupper($value)) ?? '';

        while (strlen($lettersOnly) < $length) {
            $lettersOnly .= $this->randomLetter();
        }

        return substr($lettersOnly, 0, $length);
    }

    protected function randomLetter(): string
    {
        return chr(random_int(65, 90));
    }

    /**
     * @return array<int, float>
     */
    protected function splitAmount(float $amount, int $parts): array
    {
        if ($parts <= 1) {
            return [round($amount, 2)];
        }

        $amountInCents = (int) round($amount * 100);
        $base = intdiv($amountInCents, $parts);
        $remainder = $amountInCents % $parts;

        $split = [];

        for ($index = 0; $index < $parts; $index++) {
            $centValue = $base + ($index < $remainder ? 1 : 0);
            $split[] = round($centValue / 100, 2);
        }

        return $split;
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array<string, mixed>
     */
    protected function buildItemDetails(
        ParsedBookingData $bookingData,
        OrderItemData $item,
        array $segment,
        int $segmentCount,
    ): array {
        return [
            'pnr' => $bookingData->pnr,
            'passenger_name' => $item->passengerName,
            'airline_code' => $item->airlineCode,
            'segment' => $segment,
            'segments_count' => $segmentCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array<string, mixed>
     */
    protected function buildProductDetails(OrderItemData $item, array $segment): array
    {
        return [
            'passenger_name' => $item->passengerName,
            'airline_code' => $item->airlineCode,
            'currency' => strtoupper($item->currency),
            'ticket_number' => $item->ticketNumber,
            'segment' => $segment,
        ];
    }
}
