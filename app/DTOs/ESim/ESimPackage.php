<?php

namespace App\DTOs\ESim;

readonly class ESimPackage
{
    /**
     * @param  string[]  $speeds
     * @param  array<int, array<string, string>>  $rawCountries
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $country,
        public int $dataMb,
        public int $validityDays,
        public float $price,
        public string $currency,
        public string $provider,
        public bool $unlimited = false,
        public array $speeds = [],
        public string $description = '',
        public array $rawCountries = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'country' => $this->country,
            'data_mb' => $this->dataMb,
            'validity_days' => $this->validityDays,
            'price' => $this->price,
            'currency' => $this->currency,
            'provider' => $this->provider,
            'unlimited' => $this->unlimited,
            'speeds' => $this->speeds,
            'description' => $this->description,
            'countries' => $this->rawCountries,
        ];
    }
}
