<?php

namespace App\Actions\Finance;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProcessWalletTransactions
{
    /**
     * Process wallet withdrawals and commission deposits for all master-supply order items.
     *
     * Items that already have a wallet_transaction_id or airline_transaction_id are skipped
     * (they were processed via own-credentials in CreateOrderFromBookingData, or previously).
     *
     * All wallet operations are wrapped in a DB transaction so a failed withdrawal rolls
     * back any earlier withdrawals made in the same call.
     *
     * @throws InsufficientWalletBalanceException
     */
    public function execute(Order $order, User $issuer): void
    {
        $order->loadMissing('items');

        $pendingItems = $order->items->filter(
            fn (OrderItem $item): bool => $item->wallet_transaction_id === null
                && $item->airline_transaction_id === null
                && (string) data_get($item->item_details, 'financial_source') === 'master_agency_supply'
        );

        if ($pendingItems->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($order, $pendingItems, $issuer): void {
            $walletHolder = $this->resolveWalletHolder($issuer);

            foreach ($pendingItems as $item) {
                $wallet = $walletHolder->getOrCreateCurrencyWallet((string) $item->currency);

                $withdrawAmount = $this->toMinor((string) $item->total_amount);
                $availableBalance = (int) $wallet->balance;

                if (! $wallet->canWithdraw($withdrawAmount)) {
                    throw new InsufficientWalletBalanceException(
                        currency: (string) $item->currency,
                        required: (float) $item->total_amount,
                        available: round($availableBalance / 100, 2),
                    );
                }

                $withdrawalTransaction = $wallet->withdraw($withdrawAmount, [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'type' => 'ticket_purchase',
                    'description' => 'Ticket for '.($item->item_details['passenger_name'] ?? 'Passenger'),
                ]);

                $item->update(['wallet_transaction_id' => $withdrawalTransaction->uuid]);

                $commissionAmount = $this->toMinor((string) ($item->commission_amount ?? 0));

                if ($commissionAmount > 0) {
                    $wallet->deposit($commissionAmount, [
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'type' => 'commission_earned',
                        'description' => 'Commission on ticket sale for '.($item->item_details['passenger_name'] ?? 'Passenger'),
                    ]);
                }
            }
        });
    }

    protected function resolveWalletHolder(User $fallback): User
    {
        return User::query()
            ->whereIn('role', ['admin', 'manager'])
            ->orderBy('id')
            ->first() ?? $fallback;
    }

    protected function toMinor(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
