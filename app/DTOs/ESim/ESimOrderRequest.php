<?php

namespace App\DTOs\ESim;

readonly class ESimOrderRequest
{
    public function __construct(
        public string $packageId,
        public int $quantity,
        public ?string $customerEmail = null,
        public ?string $customerName = null,
    ) {}
}
