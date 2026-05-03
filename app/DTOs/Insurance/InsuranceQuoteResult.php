<?php

namespace App\DTOs\Insurance;

readonly class InsuranceQuoteResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $success,
        public string $message,
        public float $totalPremium,
        public float $netPremium,
        public float $taxAmount,
        public ?string $currency,
        public array $raw,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'total_premium' => $this->totalPremium,
            'net_premium' => $this->netPremium,
            'tax_amount' => $this->taxAmount,
            'currency' => $this->currency,
            'raw' => $this->raw,
        ];
    }
}
