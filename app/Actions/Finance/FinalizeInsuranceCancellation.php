<?php

namespace App\Actions\Finance;

use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\User;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Support\Facades\DB;

class FinalizeInsuranceCancellation
{
    /**
     * @return array{refund_transaction_id:?string,commission_transaction_id:?string,net_wallet_effect:float}
     */
    public function execute(Order $order, OrderItem $item, User $actor): array
    {
        $walletHolder = $this->resolveWalletHolderForRefund($item, $actor);
        $wallet = $walletHolder->getOrCreateCurrencyWallet((string) $item->currency);
        $refundAmount = round((float) ($item->total_amount ?? $item->total ?? 0), 2);
        $commissionAmount = $walletHolder instanceof TenantInsuranceProvider
            ? 0.0
            : round((float) ($item->commission_amount ?? 0), 2);

        return DB::transaction(function () use ($order, $item, $wallet, $refundAmount, $commissionAmount): array {
            $refundTransaction = $wallet->depositFloat($refundAmount, [
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'type' => 'insurance_policy_cancellation_refund',
                'description' => 'Refund for cancelled insurance policy',
            ]);

            $commissionTransaction = null;

            if ($commissionAmount > 0) {
                $commissionTransaction = $wallet->withdrawFloat($commissionAmount, [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'type' => 'insurance_policy_cancellation_commission_reversal',
                    'description' => 'Commission reversal for cancelled insurance policy',
                ]);
            }

            $itemDetails = (array) $item->item_details;
            $cancellation = (array) data_get($itemDetails, 'insurance.cancellation', []);

            data_set($cancellation, 'financials.refund_transaction_id', $refundTransaction?->uuid);
            data_set($cancellation, 'financials.refund_amount', $refundAmount);
            data_set($cancellation, 'financials.commission_transaction_id', $commissionTransaction?->uuid);
            data_set($cancellation, 'financials.commission_reversal_amount', $commissionAmount);
            data_set($cancellation, 'financials.net_wallet_effect', round($refundAmount - $commissionAmount, 2));
            data_set($cancellation, 'cancelled_at', now()->toIso8601String());

            data_set($itemDetails, 'insurance.cancellation', $cancellation);

            $item->update([
                'item_details' => $itemDetails,
                'status' => 'cancelled',
                'refund_status' => 'completed',
                'transaction_type' => 'cancel',
            ]);

            $order->update([
                'status' => 'cancelled',
                'amount_refunded' => round((float) $order->amount_refunded + ($refundAmount - $commissionAmount), 2),
            ]);

            return [
                'refund_transaction_id' => $refundTransaction?->uuid,
                'commission_transaction_id' => $commissionTransaction?->uuid,
                'net_wallet_effect' => round($refundAmount - $commissionAmount, 2),
            ];
        });
    }

    protected function resolveWalletHolderForRefund(OrderItem $item, User $fallback): User|TenantInsuranceProvider
    {
        $originalWalletTransactionId = (string) ($item->wallet_transaction_id ?? '');

        if ($originalWalletTransactionId !== '') {
            $transaction = WalletTransaction::query()
                ->where('uuid', $originalWalletTransactionId)
                ->first(['wallet_id']);

            if ($transaction?->wallet_id) {
                $walletOwner = DB::table('wallets')
                    ->where('id', (int) $transaction->wallet_id)
                    ->first(['holder_type', 'holder_id']);

                if (! $walletOwner) {
                    return $fallback;
                }

                if ((string) $walletOwner->holder_type === User::class) {
                    $resolvedUser = User::query()->find((int) $walletOwner->holder_id);

                    if ($resolvedUser) {
                        return $resolvedUser;
                    }
                }

                if ((string) $walletOwner->holder_type === TenantInsuranceProvider::class) {
                    $resolvedProvider = TenantInsuranceProvider::query()->find((int) $walletOwner->holder_id);

                    if ($resolvedProvider) {
                        return $resolvedProvider;
                    }
                }
            }
        }

        return User::query()
            ->whereIn('role', ['admin', 'manager'])
            ->orderBy('id')
            ->first() ?? $fallback;
    }
}
