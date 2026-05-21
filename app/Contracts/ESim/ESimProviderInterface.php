<?php

namespace App\Contracts\ESim;

use App\DTOs\ESim\ESimOrderRequest;
use App\DTOs\ESim\ESimOrderResult;
use App\DTOs\ESim\ESimPackage;

interface ESimProviderInterface
{
    /**
     * List packages filtered by country, data size, and/or validity.
     *
     * @param  array{country?: string, data_mb?: int, validity_days?: int}  $filters
     * @return ESimPackage[]
     */
    public function catalogue(array $filters = []): array;

    /**
     * Get bundle details for a specific package.
     *
     * @return array<string, mixed>
     */
    public function bundles(string $packageId): array;

    /**
     * Place an eSIM order and return the result with ICCID and activation code.
     */
    public function processOrder(ESimOrderRequest $request): ESimOrderResult;

    /**
     * Fetch order details by provider order ID.
     *
     * @return array<string, mixed>
     */
    public function orderDetails(string $orderId): array;
}
