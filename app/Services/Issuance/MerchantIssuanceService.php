<?php

namespace App\Services\Issuance;

use App\DTOs\Accounting\IssuanceDTO;
use App\DTOs\Issuance\MerchantIssuanceRequest;
use App\Exceptions\InsufficientMerchantBalanceException;
use App\Exceptions\InsufficientProviderBalanceException;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Orders\OrderNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates a network merchant product issuance.
 *
 * Flow:
 *   1. Validate merchant wallet balance (in merchant tenant context).
 *   2. Validate agency provider wallet balance (in agency tenant context).
 *   3. Deduct both wallets via IssuanceBridgeService (cross-tenant).
 *   4. Create Order + OrderItem in the merchant tenant DB.
 *   5. Post merchant-side ledger entries (merchant books).
 *   6. Post agency-side ledger entries (agency books).
 *   7. Return the created Order.
 */
class MerchantIssuanceService
{
    public function __construct(
        private readonly IssuanceBridgeService $bridge,
        private readonly LedgerPostingService $ledger,
        private readonly OrderNumberGenerator $orderNumbers,
    ) {}

    public function issue(MerchantIssuanceRequest $request): Order
    {
        // Steps 1–2 and 3–6 all require tenant context for wallet access.
        // We resolve balances inside the correct tenant contexts.

        // Step 1: Validate merchant wallet balance (inside merchant tenant context).
        $merchantWalletBalance = null;
        tenancy()->initialize($request->merchantTenant);
        try {
            $wallet = Tenant::findOrFail($request->merchantTenant->id)->getWallet('merchant');
            $merchantWalletBalance = [
                'canWithdraw' => $wallet->canWithdrawFloat($request->wholesalePrice),
                'balance' => (float) $wallet->balanceFloat,
            ];
        } finally {
            tenancy()->end();
        }

        if (! $merchantWalletBalance['canWithdraw']) {
            throw new InsufficientMerchantBalanceException(
                required: $request->wholesalePrice,
                available: $merchantWalletBalance['balance'],
            );
        }

        // Step 2: Validate agency provider wallet balance (inside agency tenant context).
        $providerWalletSlug = match ($request->productType) {
            'airline' => 'airline-provider',
            'hotel' => 'hotel-provider',
            'insurance' => 'insurance-provider',
            'esim' => 'esim-provider',
            default => 'provider-wallet',
        };

        $providerWalletBalance = null;
        tenancy()->initialize($request->agencyTenant);
        try {
            $wallet = $request->provider->getWallet($providerWalletSlug);
            $providerWalletBalance = [
                'canWithdraw' => $wallet->canWithdrawFloat($request->providerCost),
                'balance' => (float) $wallet->balanceFloat,
            ];
        } finally {
            tenancy()->end();
        }

        if (! $providerWalletBalance['canWithdraw']) {
            throw new InsufficientProviderBalanceException(
                required: $request->providerCost,
                available: $providerWalletBalance['balance'],
            );
        }

        // Use a placeholder order ID for wallet meta; replaced after order creation.
        $placeholderOrderId = 'pending-'.Str::uuid();

        // Step 3: Deduct both wallets (cross-tenant).
        $this->bridge->deductBothTenants(
            merchantTenantId: $request->merchantTenant->id,
            agencyTenantId: $request->agencyTenant->id,
            providerId: $request->provider->id,
            merchantDeductAmount: $request->wholesalePrice,
            agencyProviderDeductAmount: $request->providerCost,
            productType: $request->productType,
            orderId: $placeholderOrderId,
            providerRef: $request->providerReference,
        );

        // Steps 4–5 run inside the merchant tenant context.
        $order = null;

        $request->merchantTenant->run(function () use ($request, &$order) {
            $order = DB::connection('tenant')->transaction(function () use ($request) {
                // Step 4: Create Order and OrderItem in merchant tenant DB.
                $createdOrder = Order::create([
                    'number' => $this->orderNumbers->generate(),
                    'owner_type' => $request->merchantTenant->getMorphClass(),
                    'owner_id' => $request->merchantTenant->id,
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
                    'order_id' => $createdOrder->id,
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

                // Step 5: Post merchant-side ledger entries.
                $dto = new IssuanceDTO(
                    orderId: $createdOrder->id,
                    productType: $request->productType,
                    sellingPrice: $request->sellingPrice,
                    vatAmount: $request->vatAmount,
                    providerCost: $request->providerCost,
                    providerReference: $request->providerReference ?: "order:{$createdOrder->id}",
                    merchantId: $request->merchantTenant->id,
                    wholesalePrice: $request->wholesalePrice,
                );

                $this->ledger->postMerchantIssuanceEntry($dto);

                return $createdOrder;
            });
        });

        tenancy()->end();

        // Step 6: Post agency-side ledger entries (in agency tenant context).
        $request->agencyTenant->run(function () use ($request, $order) {
            $this->ledger->postAgencyNetworkEntry([
                'order_id' => $order->id,
                'product_type' => $request->productType,
                'wholesale_price' => $request->wholesalePrice,
                'provider_cost' => $request->providerCost,
                'commission' => $request->wholesalePrice - $request->providerCost,
                'merchant_tenant' => $request->merchantTenant->id,
            ]);
        });

        tenancy()->end();

        return $order;
    }
}
