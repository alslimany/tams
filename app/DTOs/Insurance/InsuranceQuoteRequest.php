<?php

namespace App\DTOs\Insurance;

readonly class InsuranceQuoteRequest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $productType,
        public array $payload,
    ) {}
}
