<?php

namespace App\DTOs\Accounting;

/**
 * Data transfer object for a product issuance (airline, hotel, insurance, eSIM).
 *
 * Carries all financial figures needed by LedgerPostingService to construct
 * a balanced double-entry journal entry for the sale.
 */
class IssuanceDTO
{
    public function __construct(
        /** Internal order ID (used as journal entry reference). */
        public readonly string $orderId,

        /** Product type: airline | hotel | insurance | esim */
        public readonly string $productType,

        /** Gross selling price charged to the customer (inclusive of VAT). */
        public readonly float $sellingPrice,

        /** VAT portion of the selling price (0 if not applicable). */
        public readonly float $vatAmount,

        /** Net cost charged by the provider (deducted from provider wallet). */
        public readonly float $providerCost,

        /** Provider-issued reference: PNR, booking ID, policy number, etc. */
        public readonly string $providerReference,

        /** Set when this is a merchant issuance (merchant tenant ID). */
        public readonly ?string $merchantId = null,

        /** Wholesale price the merchant pays the network agency. */
        public readonly ?float $wholesalePrice = null,
    ) {}
}
