<?php

namespace App\Actions\Finance;

use App\Models\Tenant\AirlineAccount;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use Illuminate\Support\Facades\DB;

class CreateAirlineTransactions
{
    public function execute(Order $order): void
    {
        $order->loadMissing('items');

        DB::transaction(function () use ($order): void {
            foreach ($order->items as $item) {
                if ($item->airline_transaction_id !== null || $item->wallet_transaction_id !== null) {
                    continue;
                }

                $financialSource = (string) data_get($item->item_details, 'financial_source', '');
                if ($financialSource !== 'own_credentials') {
                    continue;
                }

                $provider = $this->resolveProviderForItem($item);
                if (! $provider) {
                    continue;
                }

                $this->createAirlineAccountTransaction($item, $provider, (string) $item->provider_reference);
            }
        });
    }

    protected function createAirlineAccountTransaction(OrderItem $item, TenantProvider $provider, string $reference): void
    {
        $account = AirlineAccount::query()->firstOrCreate([
            'tenant_provider_id' => $provider->id,
            'currency' => (string) $item->currency,
        ]);

        $currentBalance = (float) $account->balance;
        $itemTotal = (float) $item->total_amount;
        $newBalance = $currentBalance - $itemTotal;

        $transaction = $account->transactions()->create([
            'type' => 'ticket_cost',
            'amount' => -$itemTotal,
            'balance_after' => $newBalance,
            'order_item_id' => $item->id,
            'external_reference' => $reference,
            'description' => 'Ticket for '.((string) data_get($item->item_details, 'passenger_name', 'Passenger')),
        ]);

        $account->update(['balance' => $newBalance]);
        $item->update(['airline_transaction_id' => $transaction->id]);
    }

    protected function resolveProviderForItem(OrderItem $item): ?TenantProvider
    {
        $providerId = data_get($item->item_details, 'financial_provider_id');
        if (is_numeric($providerId)) {
            return TenantProvider::query()->whereKey((int) $providerId)->where('is_active', true)->first();
        }

        $airlineCode = (string) data_get($item->item_details, 'airline_code', data_get($item->product_details, 'airline_code', ''));
        if ($airlineCode === '') {
            return null;
        }

        return TenantProvider::query()
            ->where('airline_code', strtoupper($airlineCode))
            ->where('is_active', true)
            ->get()
            ->first(function (TenantProvider $provider) use ($item): bool {
                $providerCurrency = strtoupper((string) data_get($provider->credentials, 'currency', ''));

                return $providerCurrency === '' || $providerCurrency === strtoupper((string) $item->currency);
            });
    }
}
