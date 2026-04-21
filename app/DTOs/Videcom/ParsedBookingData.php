<?php

namespace App\DTOs\Videcom;

class ParsedBookingData
{
    /**
     * @param  array<int, OrderItemData>  $items
     */
    public function __construct(
        public string $pnr,
        public float $grandTotal,
        public string $currency,
        public string $paymentMethod,
        public ?string $paymentReference,
        public array $items,
    ) {}

    public function toArray(): array
    {
        return [
            'pnr' => $this->pnr,
            'grand_total' => $this->grandTotal,
            'currency' => $this->currency,
            'payment_method' => $this->paymentMethod,
            'payment_reference' => $this->paymentReference,
            'items' => array_map(fn (OrderItemData $item): array => $item->toArray(), $this->items),
        ];
    }
}
