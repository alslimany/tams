<?php

namespace App\Actions\Finance;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantHotelProvider;
use Bavix\Wallet\Models\Transaction;
use Illuminate\Support\Facades\DB;

class ProcessHotelProviderWalletTransactions
{
    /**
     * @throws InsufficientWalletBalanceException
     */
    public function assertCanWithdraw(TenantHotelProvider $provider, string $currency, float $amount): void
    {
        $required = round($amount, 2);

        if ($required <= 0) {
            return;
        }

        $wallet = $provider->getOrCreateCurrencyWallet($currency);
        $available = round((float) $wallet->balanceFloat, 2);

        if (! $wallet->canWithdrawFloat($required)) {
            throw new InsufficientWalletBalanceException(strtoupper($currency), $required, $available);
        }
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function execute(Order $order, TenantHotelProvider $provider): void
    {
        $order->loadMissing('items');

        DB::transaction(function () use ($order, $provider): void {
            foreach ($order->items as $item) {
                $this->executeForItem($order, $item, $provider);
            }
        });
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function executeForItem(Order $order, OrderItem $item, TenantHotelProvider $provider): ?Transaction
    {
        if ($item->wallet_transaction_id !== null) {
            return null;
        }

        $amount = round((float) data_get($item->item_details, 'provider_cost', $item->net_fare ?? $item->total_amount ?? $item->total ?? 0), 2);
        if ($amount <= 0) {
            return null;
        }

        $currency = strtoupper((string) ($item->currency ?? $order->currency ?? 'USD'));
        $this->assertCanWithdraw($provider, $currency, $amount);

        $wallet = $provider->getOrCreateCurrencyWallet($currency);
        $withdrawal = $wallet->withdrawFloat($amount, $this->metadataForWithdrawal($order, $item, $provider));

        $details = (array) $item->item_details;
        $details['provider_wallet_transaction_id'] = $withdrawal->uuid;
        $details['provider_wallet_withdrawal_amount'] = round(abs((float) $withdrawal->amount) / 100, 2);

        $item->update([
            'wallet_transaction_id' => $withdrawal->uuid,
            'item_details' => $details,
        ]);

        return $withdrawal;
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadataForWithdrawal(Order $order, OrderItem $item, TenantHotelProvider $provider): array
    {
        $reference = (string) ($item->provider_reference ?: $item->ticket_number);

        return [
            'type' => 'provider_issuance_cost',
            'provider_type' => 'hotel',
            'hotel_provider_type' => $provider->provider_type,
            'provider_id' => $provider->id,
            'tenant_id' => tenant()?->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_number' => $order->number,
            'product_type' => 'hotel',
            'product_subtype' => (string) $item->product_subtype,
            'provider_reference' => $reference,
        ];
    }
}
