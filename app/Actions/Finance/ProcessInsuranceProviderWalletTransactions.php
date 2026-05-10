<?php

namespace App\Actions\Finance;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantInsuranceProvider;
use Bavix\Wallet\Models\Transaction;
use Illuminate\Support\Facades\DB;

class ProcessInsuranceProviderWalletTransactions
{
    /**
     * @throws InsufficientWalletBalanceException
     */
    public function assertCanWithdraw(TenantInsuranceProvider $provider, string $currency, float $amount): void
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
    public function execute(Order $order, TenantInsuranceProvider $provider): void
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
    public function executeForItem(Order $order, OrderItem $item, TenantInsuranceProvider $provider): ?Transaction
    {
        if ($item->wallet_transaction_id !== null) {
            return null;
        }

        $amount = round((float) ($item->total_amount ?? $item->total ?? 0), 2);
        if ($amount <= 0) {
            return null;
        }

        $currency = strtoupper((string) ($item->currency ?? $order->currency ?? 'LYD'));
        $this->assertCanWithdraw($provider, $currency, $amount);

        $wallet = $provider->getOrCreateCurrencyWallet($currency);
        $withdrawal = $wallet->withdrawFloat($amount, $this->metadataForWithdrawal($order, $item, $provider));

        $item->update(['wallet_transaction_id' => $withdrawal->uuid]);

        return $withdrawal;
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadataForWithdrawal(Order $order, OrderItem $item, TenantInsuranceProvider $provider): array
    {
        $reference = (string) ($item->provider_reference ?: $item->ticket_number);

        return [
            'type' => 'provider_issuance_cost',
            'provider_type' => 'insurance',
            'insurance_provider_type' => $provider->provider_type,
            'provider_id' => $provider->id,
            'tenant_id' => tenant()?->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_number' => $order->number,
            'product_type' => (string) ($item->product_type ?: 'insurance'),
            'product_subtype' => (string) $item->product_subtype,
            'provider_reference' => $reference,
            'external_reference' => $reference,
            'financial_source' => data_get($item->item_details, 'financial_source'),
            'description' => 'Insurance policy issuance deduction.',
        ];
    }
}
