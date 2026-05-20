<?php

namespace App\DTOs\Issuance;

use App\Models\Tenant;
use App\Models\TenantProvider;

/**
 * Carries all inputs needed by MerchantIssuanceService to issue a product
 * through a network agency on behalf of a merchant tenant.
 */
class MerchantIssuanceRequest
{
    public function __construct(
        /** The merchant tenant performing the sale to the end customer. */
        public readonly Tenant $merchantTenant,

        /** The network agency tenant that owns the provider credentials. */
        public readonly Tenant $agencyTenant,

        /** The provider (owned by the agency) whose wallet will be debited. */
        public readonly TenantProvider $provider,

        /** Product type: airline | hotel | insurance | esim */
        public readonly string $productType,

        /** Gross selling price charged to the end customer (inclusive of VAT). */
        public readonly float $sellingPrice,

        /** VAT portion of the selling price. */
        public readonly float $vatAmount,

        /**
         * Wholesale price the merchant pays the network agency.
         * This is the merchant's cost of goods.
         */
        public readonly float $wholesalePrice,

        /** Net cost the agency pays the provider. */
        public readonly float $providerCost,

        /** Provider-issued reference: PNR, booking ID, policy number, etc. */
        public readonly string $providerReference = '',

        /** Currency code (ISO 4217). Defaults to LYD. */
        public readonly string $currency = 'LYD',
    ) {}
}
