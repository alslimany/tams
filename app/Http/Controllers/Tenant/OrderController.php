<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AirlineTransaction;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\Videcom\VidecomPnrParser;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Inertia\Inertia;
use Inertia\Response;
use SimpleXMLElement;

class OrderController extends Controller
{
    public function index(): Response
    {
        $orders = Order::query()
            ->with([
                'owner',
                'items:id,order_id,type,product_subtype,provider,provider_reference,ticket_number,total,currency,status,item_details',
            ])
            ->withCount('items')
            ->latest('issued_at')
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
        ]);
    }

    public function show(Order $order): Response
    {
        $this->syncOrderItemsFromPnrQuery($order);

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

        $voidRefundAccount = User::query()
            ->whereIn('role', ['admin', 'manager'])
            ->orderBy('id')
            ->first(['id', 'name', 'email', 'role']);

        return Inertia::render('Orders/Show', [
            'order' => $order,
            'itemTransactions' => $itemTransactions,
            'voidRefundAccount' => $voidRefundAccount,
        ]);
    }

    protected function syncOrderItemsFromPnrQuery(Order $order): void
    {
        $order->loadMissing('items');

        $pnrGroups = $order->items
            ->filter(fn (OrderItem $item): bool => filled($item->provider_reference) && ! in_array($item->status, ['voided', 'refunded'], true))
            ->groupBy('provider_reference');

        foreach ($pnrGroups as $pnr => $items) {
            try {
                $airlineCode = (string) ($items->first()?->item_details['airline_code'] ?? $items->first()?->item_details['iata'] ?? '');
                if ($airlineCode === '') {
                    continue;
                }

                $providerConfig = TenantProvider::query()
                    ->where('airline_code', $airlineCode)
                    ->where('is_active', true)
                    ->first();

                if (! $providerConfig) {
                    continue;
                }

                $provider = ProviderFactory::make($providerConfig);
                $pnrResponse = $provider->queryPnr((string) $pnr);
                $pnrXml = $this->toXml($pnrResponse);

                if (! $pnrXml instanceof SimpleXMLElement) {
                    continue;
                }

                $parsed = VidecomPnrParser::parse($pnrXml);
                $formattedPnr = VidecomPnrParser::formatForOrderDetails($pnrXml);
                $ticketsByNumber = collect($parsed['tickets'] ?? [])->groupBy('ticket_number');
                $fallbackTicket = collect($parsed['tickets'] ?? [])->first() ?? [];

                foreach ($items as $item) {
                    $ticketNumber = (string) ($item->ticket_number ?? '');
                    $ticketRows = $ticketNumber !== '' ? $ticketsByNumber->get($ticketNumber) : null;
                    $firstTicket = $ticketRows?->first() ?? $fallbackTicket;

                    $details = $formattedPnr;
                    $details['pnr_synced_at'] = now()->toIso8601String();

                    $item->update([
                        'provider_reference' => (string) ($parsed['rloc'] ?? $pnr),
                        'ticket_number' => (string) ($firstTicket['ticket_number'] ?? $item->ticket_number),
                        'item_details' => $details,
                        'status' => $this->mapTicketStatus((string) ($firstTicket['status'] ?? $item->status)),
                    ]);
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    protected function mapTicketStatus(string $providerStatus): string
    {
        return match (strtoupper(trim($providerStatus))) {
            'F', 'O', 'OK', 'HK' => 'issued',
            'V', 'VOID', 'VO', 'X' => 'voided',
            'R', 'RFND', 'REFUNDED' => 'refunded',
            default => 'issued',
        };
    }

    protected function toXml(mixed $response): ?SimpleXMLElement
    {
        if ($response instanceof SimpleXMLElement) {
            return $response;
        }

        if (! is_string($response)) {
            return null;
        }

        try {
            $xml = simplexml_load_string($response);

            return $xml instanceof SimpleXMLElement ? $xml : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
