<?php

namespace App\DTOs\Issuance;

use App\Models\Tenant;
use App\Models\TenantProvider;

/**
 * Carries all inputs needed by DirectAgencyIssuanceService to issue a product.
 */
class DirectIssuanceRequest
{
    public function __construct(
        /** The agency (tenant) performing the issuance. */
        public readonly Tenant $agency,

        /** The provider whose wallet will be debited for the cost. */
        public readonly TenantProvider $provider,

        /** Product type: airline | hotel | insurance | esim */
        public readonly string $productType,

        /** Gross selling price charged to the customer (inclusive of VAT). */
        public readonly float $sellingPrice,

        /** VAT portion of the selling price (0 if not applicable). */
        public readonly float $vatAmount,

        /** Net cost charged by the provider. */
        public readonly float $providerCost,

        /** Provider-issued reference: PNR, booking ID, policy number, etc. */
        public readonly string $providerReference = '',

        /** Currency code (ISO 4217). Defaults to LYD. */
        public readonly string $currency = 'LYD',

        /**
         * When true, the agency operating wallet is also validated for sufficient balance
         * before issuance proceeds (used when the agency pre-pays on behalf of the customer).
         */
        public readonly bool $trackCustomerBalance = false,
    ) {}
}
