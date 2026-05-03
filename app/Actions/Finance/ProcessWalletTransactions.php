<?php

namespace App\Actions\Finance;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\AgencyWalletTransaction;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProcessWalletTransactions
{
    /**
     * @param  array<string, float|int|string>  $requiredByCurrency
     *
     * @throws InsufficientWalletBalanceException
     */
    public function assertCanIssueForAmounts(array $requiredByCurrency, User $issuer, bool $allowNegativeBalance = false): void
    {
        if ($allowNegativeBalance) {
            return;
        }

        $walletHolder = $this->resolveWalletHolder($issuer);

        foreach ($requiredByCurrency as $currency => $requiredAmount) {
            $required = round((float) $requiredAmount, 2);
            if ($required <= 0) {
                continue;
            }

            $wallet = $walletHolder->getOrCreateCurrencyWallet((string) $currency);
            $available = round((float) $wallet->balanceFloat, 2);

            if (! $wallet->canWithdrawFloat($required)) {
                throw new InsufficientWalletBalanceException(
                    currency: (string) $currency,
                    required: $required,
                    available: $available,
                );
            }
        }
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function assertSufficientBalances(Order $order, User $issuer, bool $allowNegativeBalance = false): void
    {
        $order->loadMissing('items');

        $pendingItems = $order->items->filter(
            fn (OrderItem $item): bool => $item->wallet_transaction_id === null
                && $item->airline_transaction_id === null
                && (string) data_get($item->item_details, 'financial_source') === 'master_agency_supply'
        );

        $requiredByCurrency = [];

        foreach ($pendingItems as $item) {
            $currency = (string) $item->currency;
            $requiredByCurrency[$currency] = round(((float) ($requiredByCurrency[$currency] ?? 0)) + (float) ($item->total_amount ?? 0), 2);
        }

        $this->assertCanIssueForAmounts($requiredByCurrency, $issuer, $allowNegativeBalance);
    }

    /**
     * Process wallet withdrawals for all master-supply order items.
     *
     * Items that already have a wallet_transaction_id or airline_transaction_id are skipped
     * (they were processed via own-credentials in CreateOrderFromBookingData, or previously).
     *
     * All wallet operations are wrapped in a DB transaction so a failed withdrawal rolls
     * back any earlier withdrawals made in the same call.
     *
     * When a default agency is configured, landlord-level agency wallet transactions are
     * recorded for ticket cost deductions and master commission payments.
     *
     * @throws InsufficientWalletBalanceException
     */
    public function execute(Order $order, User $issuer, bool $allowNegativeBalance = false): void
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

        $this->assertSufficientBalances($order, $issuer, $allowNegativeBalance);

        DB::transaction(function () use ($order, $pendingItems, $issuer, $allowNegativeBalance): void {
            $walletHolder = $this->resolveWalletHolder($issuer);

            foreach ($pendingItems as $item) {
                $wallet = $walletHolder->getOrCreateCurrencyWallet((string) $item->currency);

                $withdrawalTransaction = $allowNegativeBalance
                    ? $wallet->forceWithdrawFloat((float) $item->total_amount, [
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'type' => 'ticket_purchase',
                        'description' => 'Ticket for '.($item->item_details['passenger_name'] ?? 'Passenger'),
                    ])
                    : $wallet->withdrawFloat((float) $item->total_amount, [
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'type' => 'ticket_purchase',
                        'description' => 'Ticket for '.($item->item_details['passenger_name'] ?? 'Passenger'),
                    ]);

                $item->update(['wallet_transaction_id' => $withdrawalTransaction->uuid]);

                // Record landlord-level agency wallet transactions for settlement reporting.
                $this->recordLandlordTransactions($order, $item, $allowNegativeBalance);
            }
        });
    }

    /**
     * Record ticket cost deduction and master commission in the landlord DB.
     */
    protected function recordLandlordTransactions(Order $order, OrderItem $item, bool $allowNegativeBalance = false): void
    {
        $financialSource = data_get($item->item_details, 'financial_source');
        $defaultAgencyTenantId = data_get($item->item_details, 'default_agency_tenant_id');
        if ($financialSource !== 'master_agency_supply' || $defaultAgencyTenantId === null) {
            return;
        }

        $tenantId = tenant()?->id;
        $currency = (string) $item->currency;
        $ticketCost = (float) $item->total_amount;

        // Record ticket cost deduction from the agency's landlord wallet.
        $currentBalance = $this->getLandlordWalletBalance($tenantId, $currency);
        $newBalance = $allowNegativeBalance
            ? $currentBalance - $ticketCost
            : max(0, $currentBalance - $ticketCost);

        AgencyWalletTransaction::recordTicketDeduction(
            tenantId: $tenantId,
            defaultAgencyTenantId: $defaultAgencyTenantId,
            currency: $currency,
            amount: $ticketCost,
            balanceAfter: $newBalance,
            orderId: $order->id,
        );

        // Commission is tracked as payable for later settlement; no cross-tenant transfer is executed here.
        $commissionPayable = (float) ($item->agent_commission ?? 0);

        if ($commissionPayable > 0) {
            AgencyWalletTransaction::recordCommissionPayable(
                tenantId: $tenantId,
                defaultAgencyTenantId: $defaultAgencyTenantId,
                currency: $currency,
                amount: $commissionPayable,
                balanceAfter: $newBalance,
                orderId: $order->id,
                orderItemId: (string) $item->id,
            );
        }
    }

    /**
     * Get the current wallet balance for a tenant from the landlord tracking table.
     */
    protected function getLandlordWalletBalance(string $tenantId, string $currency): float
    {
        $lastTransaction = AgencyWalletTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('currency', $currency)
            ->latest('id')
            ->first();

        return (float) ($lastTransaction?->balance_after ?? 0);
    }

    protected function resolveWalletHolder(User $fallback): User
    {
        return User::query()
            ->whereIn('role', ['admin', 'manager'])
            ->orderBy('id')
            ->first() ?? $fallback;
    }
}
