<?php

namespace App\Services\Issuance;

use App\DTOs\Accounting\IssuanceDTO;
use App\DTOs\Issuance\DirectIssuanceRequest;
use App\Exceptions\InsufficientCustomerBalanceException;
use App\Exceptions\InsufficientProviderBalanceException;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Orders\OrderNumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates a direct-agency product issuance.
 *
 * Flow:
 *   1. Validate provider wallet balance.
 *   2. Optionally validate agency operating wallet balance.
 *   3. (Inside DB transaction)
 *      a. Deduct provider wallet — event fires, listener auto-posts a simple ledger entry.
 *      b. Create Order + OrderItem records.
 *      c. Post the full multi-line issuance journal entry (revenue, VAT, cost).
 *   4. Return the created Order.
 *
 * The provider API call is intentionally outside the DB transaction because it is a
 * network call. Pass a pre-resolved $providerReference on the request when the API
 * has already been called upstream (e.g. from a controller). For test scenarios,
 * pass any non-empty string as the reference.
 */
class DirectAgencyIssuanceService
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
        private readonly OrderNumberGenerator $orderNumbers,
    ) {}

    public function issue(DirectIssuanceRequest $request): Order
    {
        $providerWallet = $request->provider->getWallet(
            $this->ledger->resolveJournal($request->productType) === 'AIR'
                ? 'airline-provider'
                : "{$request->productType}-provider"
        );

        $agencyWallet = $request->agency->getWallet('operating');

        // Step 1: Validate provider wallet balance.
        if (! $providerWallet->canWithdrawFloat($request->providerCost)) {
            throw new InsufficientProviderBalanceException(
                required: $request->providerCost,
                available: (float) $providerWallet->balanceFloat,
            );
        }

        // Step 2: Optionally validate agency operating wallet balance.
        if ($request->trackCustomerBalance && $agencyWallet !== null) {
            if (! $agencyWallet->canWithdrawFloat($request->sellingPrice)) {
                throw new InsufficientCustomerBalanceException(
                    required: $request->sellingPrice,
                    available: (float) $agencyWallet->balanceFloat,
                );
            }
        }

        return DB::connection('tenant')->transaction(function () use ($request, $providerWallet) {
            // Step 3a: Deduct provider wallet.
            // No ledger_accounts in meta — the full issuance journal entry (Step 3c)
            // is the canonical record. The listener only fires for ad-hoc wallet
            // transactions that carry ledger_accounts metadata.
            $providerWallet->withdrawFloat($request->providerCost, [
                'order_type' => $request->productType,
                'tx_type' => 'issuance',
                'reference' => $request->providerReference,
            ]);

            // Step 3b: Create Order and OrderItem.
            $order = Order::create([
                'number' => $this->orderNumbers->generate(),
                'owner_type' => $request->agency->getMorphClass(),
                'owner_id' => $request->agency->id,
                'status' => 'issued',
                'issued_at' => now(),
                'subtotal' => $request->sellingPrice - $request->vatAmount,
                'tax_total' => $request->vatAmount,
                'grand_total' => $request->sellingPrice,
                'amount_paid' => $request->sellingPrice,
                'amount_refunded' => 0,
                'currency' => $request->currency,
                'payment_method' => 'wallet',
                'payment_reference' => $request->providerReference ?: null,
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'type' => $request->productType,
                'provider' => $request->provider->provider_type ?? $request->productType,
                'provider_reference' => $request->providerReference ?: null,
                'item_details' => [],
                'price' => $request->sellingPrice - $request->vatAmount,
                'taxes' => $request->vatAmount,
                'total' => $request->sellingPrice,
                'currency' => $request->currency,
                'status' => 'issued',
            ]);

            // Step 3c: Post the full balanced issuance journal entry.
            $dto = new IssuanceDTO(
                orderId: $order->id,
                productType: $request->productType,
                sellingPrice: $request->sellingPrice,
                vatAmount: $request->vatAmount,
                providerCost: $request->providerCost,
                providerReference: $request->providerReference ?: "order:{$order->id}",
            );

            $this->ledger->postIssuanceEntry($dto);

            return $order;
        });
    }
}
