<?php

namespace App\Services\Issuance;

use App\Models\Tenant\Order;
use App\Models\TenantProvider;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates a product cancellation (void / refund).
 *
 * Flow (inside a single DB transaction):
 *   1. Restore the provider wallet — depositFloat() returns the prepaid asset.
 *   2. Update the Order status to 'cancelled' and record the refunded amount.
 *   3. Post a reversal journal entry that mirrors and negates the original issuance.
 *      If a cancellation fee applies, the fee is retained in account 4700 and the
 *      refund to the customer is reduced accordingly.
 *
 * The provider API void/refund call must be performed by the caller BEFORE invoking
 * this service, just as the issuance API call is performed before DirectAgencyIssuanceService.
 */
class CancellationService
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * Cancel an issued order and post the reversal journal entry.
     *
     * @param  Order  $order  The order to cancel (must be in 'issued' status).
     * @param  string  $productType  airline | hotel | insurance | esim
     * @param  float  $sellingPrice  Original gross selling price (inclusive of VAT).
     * @param  float  $vatAmount  VAT portion of the selling price.
     * @param  float  $providerCost  Net cost originally charged by the provider.
     * @param  TenantProvider  $provider  The provider whose wallet will be restored.
     * @param  float|null  $cancellationFee  Fee retained by the agency (deducted from refund).
     */
    public function cancel(
        Order $order,
        string $productType,
        float $sellingPrice,
        float $vatAmount,
        float $providerCost,
        TenantProvider $provider,
        ?float $cancellationFee = null,
    ): Order {
        $walletName = $this->ledger->resolveJournal($productType) === 'AIR'
            ? 'airline-provider'
            : "{$productType}-provider";

        $providerWallet = $provider->getWallet($walletName);

        return DB::connection('tenant')->transaction(function () use (
            $order,
            $productType,
            $sellingPrice,
            $vatAmount,
            $providerCost,
            $providerWallet,
            $cancellationFee,
        ) {
            // Step 1: Restore provider wallet.
            $providerWallet->depositFloat($providerCost, [
                'order_type' => $productType,
                'tx_type' => 'cancellation',
                'order_id' => $order->id,
            ]);

            // Step 2: Update order status and refunded amount.
            $refundAmount = $sellingPrice - ($cancellationFee ?? 0.0);
            $order->update([
                'status' => 'cancelled',
                'amount_refunded' => $refundAmount,
            ]);

            // Step 3: Post the reversal journal entry.
            $this->ledger->postReversalEntry(
                originalOrderId: $order->id,
                sellingPrice: $sellingPrice,
                productType: $productType,
                vatAmount: $vatAmount,
                providerCost: $providerCost,
                cancellationFee: $cancellationFee,
            );

            return $order->fresh();
        });
    }
}
