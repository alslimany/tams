<?php

namespace App\DTOs\ESim;

readonly class ESimOrderRequest
{
    public function __construct(
        public string $packageId,
        public int $quantity = 1,
        public ?string $customerEmail = null,
        public ?string $customerName = null,
        public ?string $iccid = null,
    ) {}
}
