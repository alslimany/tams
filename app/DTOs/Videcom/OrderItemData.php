<?php

namespace App\DTOs\Videcom;

class OrderItemData
{
    public function __construct(
        public string $passengerName,
        public array $segments,
        public float $fare,
        public float $taxes,
        public float $total,
        public ?string $ticketNumber = null,
        public float $commission = 0.0,
        public ?string $airlineCode = null,
        public string $currency = 'USD',
    ) {}

    public function toArray(): array
    {
        return [
            'passenger_name' => $this->passengerName,
            'segments' => $this->segments,
            'fare' => $this->fare,
            'taxes' => $this->taxes,
            'total' => $this->total,
            'ticket_number' => $this->ticketNumber,
            'commission' => $this->commission,
            'airline_code' => $this->airlineCode,
            'currency' => $this->currency,
        ];
    }
}
