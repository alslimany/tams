<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AirlineTransaction;
use App\Models\Tenant\Order;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function show(Order $order): Response
    {
        $order->load(['owner', 'items.airlineTransaction.account', 'statusLogs.user']);

        $walletTransactionUuids = $order->items
            ->pluck('wallet_transaction_id')
            ->filter()
            ->values()
            ->all();

        $walletTransactions = WalletTransaction::query()
            ->whereIn('uuid', $walletTransactionUuids)
            ->get(['id', 'uuid', 'type', 'amount', 'meta', 'created_at'])
            ->keyBy('uuid');

        $airlineTransactions = AirlineTransaction::query()
            ->whereIn('id', $order->items->pluck('airline_transaction_id')->filter()->all())
            ->get(['id', 'type', 'amount', 'balance_after', 'external_reference', 'description', 'created_at'])
            ->keyBy('id');

        $itemTransactions = $order->items->map(function ($item) use ($walletTransactions, $airlineTransactions): array {
            return [
                'order_item_id' => $item->id,
                'wallet_transaction' => $item->wallet_transaction_id
                    ? $walletTransactions->get($item->wallet_transaction_id)
                    : null,
                'airline_transaction' => $item->airline_transaction_id
                    ? $airlineTransactions->get($item->airline_transaction_id)
                    : null,
            ];
        })->values();

        return Inertia::render('Orders/Show', [
            'order' => $order,
            'itemTransactions' => $itemTransactions,
        ]);
    }
}
