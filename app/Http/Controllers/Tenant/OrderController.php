<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AgencySetting;
use App\Models\Tenant\AirlineTransaction;
use App\Models\Tenant as CentralTenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\Videcom\VidecomPnrParser;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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
                $pnrResponse = $this->queryPnrFromSourceProvider($items, (string) $pnr);

                if ($pnrResponse === null) {
                    continue;
                }

                $pnrXml = $this->toXml($pnrResponse);

                if (! $pnrXml instanceof SimpleXMLElement) {
                    continue;
                }

                $parsed = VidecomPnrParser::parse($pnrXml);
                if (collect($parsed['tickets'] ?? [])->isEmpty()) {
                    continue;
                }

                $formattedPnr = VidecomPnrParser::formatForOrderDetails($pnrXml);
                $ticketsByNumber = collect($parsed['tickets'] ?? [])->groupBy('ticket_number');
                $fallbackTicket = collect($parsed['tickets'] ?? [])->first() ?? [];

                foreach ($items as $item) {
                    $ticketNumber = (string) ($item->ticket_number ?? '');
                    $ticketRows = $ticketNumber !== '' ? $ticketsByNumber->get($ticketNumber) : null;
                    $firstTicket = $ticketRows?->first() ?? $fallbackTicket;

                    $financialMetadata = Arr::only((array) $item->item_details, [
                        'financial_source',
                        'financial_provider_id',
                        'financial_source_tenant_id',
                        'default_agency_tenant_id',
                        'master_commission_rate',
                        'settlement_source',
                    ]);

                    $details = array_merge($formattedPnr, $financialMetadata);
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

    protected function queryPnrFromSourceProvider(Collection $items, string $pnr): mixed
    {
        /** @var OrderItem|null $firstItem */
        $firstItem = $items->first();
        $airlineCode = (string) ($firstItem?->item_details['airline_code'] ?? $firstItem?->item_details['iata'] ?? '');

        if ($airlineCode === '') {
            return null;
        }

        $isMasterSupply = (string) data_get($firstItem?->item_details, 'financial_source') === 'master_agency_supply';
        $defaultAgencyTenantId = (string) data_get($firstItem?->item_details, 'default_agency_tenant_id', '');
        $sourceTenantId = (string) (data_get($firstItem?->item_details, 'financial_source_tenant_id') ?: $defaultAgencyTenantId);

        if ($sourceTenantId === '') {
            $orderPaymentMethod = (string) ($firstItem?->relationLoaded('order')
                ? data_get($firstItem->order, 'payment_method', '')
                : (string) $firstItem?->order()->value('payment_method'));

            $shouldUseDefaultAgency = $isMasterSupply || $orderPaymentMethod === 'default_agency_supply';

            if ($shouldUseDefaultAgency) {
                $sourceTenantId = (string) (
                    AgencySetting::current()->default_agency_tenant_id
                    ?: CentralTenant::getDefaultAgency()?->id
                    ?: ''
                );
                $isMasterSupply = $sourceTenantId !== '';
            }
        }

        if ($isMasterSupply && $sourceTenantId !== '') {
            $sourceTenant = CentralTenant::query()->find($sourceTenantId);

            if ($sourceTenant) {
                return $sourceTenant->run(function () use ($firstItem, $airlineCode, $pnr): mixed {
                    $providerConfig = $this->resolveProviderForSync($firstItem, $airlineCode);

                    if (! $providerConfig) {
                        return null;
                    }

                    $provider = ProviderFactory::make($providerConfig);

                    return $provider->queryPnr($pnr);
                });
            }
        }

        $providerConfig = $this->resolveProviderForSync($firstItem, $airlineCode);

        if (! $providerConfig) {
            return null;
        }

        $provider = ProviderFactory::make($providerConfig);

        return $provider->queryPnr($pnr);
    }

    protected function resolveProviderForSync(?OrderItem $item, string $airlineCode): ?TenantProvider
    {
        $providerId = data_get($item?->item_details, 'financial_provider_id');

        if (is_numeric($providerId)) {
            $provider = TenantProvider::query()->whereKey((int) $providerId)->where('is_active', true)->first();

            if ($provider) {
                return $provider;
            }
        }

        return TenantProvider::query()
            ->where('airline_code', $airlineCode)
            ->where('is_active', true)
            ->first();
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
