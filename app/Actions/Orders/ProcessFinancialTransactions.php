<?php

namespace App\Actions\Orders;

use App\Actions\Finance\ProcessProviderWalletTransactions;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use Bavix\Wallet\Exceptions\InsufficientFunds;
use Illuminate\Support\Facades\DB;

class ProcessFinancialTransactions
{
    public function execute(Order $order, ?TenantProvider $bookingProvider, User $issuer): void
    {
        $order->loadMissing('items');

        DB::transaction(function () use ($order, $bookingProvider, $issuer): void {
            $resolvedPaymentMethod = null;

            foreach ($order->items as $item) {
                if ($item->wallet_transaction_id) {
                    continue;
                }

                $airlineCode = $item->item_details['airline_code'] ?? $bookingProvider?->airline_code;
                $provider = $this->resolveProviderForItem($bookingProvider, $airlineCode, (string) $item->currency);

                $usesOwnCredentials = tenant()?->usesOwnAirlineCredentials($airlineCode);
                $usesAirlineWallet = in_array((string) $order->payment_method, ['airline_account', 'airline_token'], true);

                if ($provider && ($usesOwnCredentials || $usesAirlineWallet)) {
                    $this->createProviderWalletTransaction($order, $item, $provider);
                    $resolvedPaymentMethod ??= 'airline_account';

                    continue;
                }

                $this->createWalletTransactions($order, $item, $issuer);
                $resolvedPaymentMethod = $resolvedPaymentMethod && $resolvedPaymentMethod !== 'wallet'
                    ? 'mixed'
                    : 'wallet';
            }

            if ($resolvedPaymentMethod) {
                $order->update(['payment_method' => $resolvedPaymentMethod]);
            }
        });
    }

    protected function createProviderWalletTransaction(Order $order, OrderItem $item, TenantProvider $provider): void
    {
        app(ProcessProviderWalletTransactions::class)->executeForItem($order, $item, $provider);
    }

    protected function createWalletTransactions(Order $order, OrderItem $item, User $issuer): void
    {
        $walletHolder = $this->resolveAgencyWalletHolder($issuer);
        $wallet = $walletHolder->getOrCreateCurrencyWallet($item->currency);

        $withdrawAmount = (float) $item->total;

        try {
            $withdrawTransaction = $wallet->withdrawFloat($withdrawAmount, [
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'type' => 'ticket_purchase',
                'description' => 'Ticket for '.($item->item_details['passenger_name'] ?? 'Passenger'),
            ]);
        } catch (InsufficientFunds $exception) {
            throw new InsufficientFunds('Insufficient wallet balance for order item '.$item->id);
        }

        $item->update([
            'wallet_transaction_id' => $withdrawTransaction->uuid,
        ]);

        $commission = (float) ($item->agent_commission ?? 0);
        if ($commission > 0) {
            $wallet->depositFloat($commission, [
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'type' => 'commission_earned',
                'description' => 'Commission on ticket sale',
            ]);
        }
    }

    protected function resolveAgencyWalletHolder(User $fallback): User
    {
        return User::query()
            ->where(function ($query): void {
                $query->where('role', 'admin')
                    ->orWhere('role', 'manager');
            })
            ->orderBy('id', 'asc')
            ->first() ?? $fallback;
    }

    protected function resolveProviderForItem(?TenantProvider $bookingProvider, ?string $airlineCode, string $currency): ?TenantProvider
    {
        if ($bookingProvider && $bookingProvider->is_active) {
            $providerCurrency = strtoupper((string) data_get($bookingProvider->credentials, 'currency', ''));
            if ($providerCurrency === '' || $providerCurrency === strtoupper($currency)) {
                return $bookingProvider;
            }
        }

        if (! $airlineCode) {
            return null;
        }

        return TenantProvider::query()
            ->where('airline_code', $airlineCode)
            ->where('is_active', true)
            ->get()
            ->first(function (TenantProvider $provider) use ($currency): bool {
                return strtoupper((string) data_get($provider->credentials, 'currency', '')) === strtoupper($currency);
            });
    }
}
