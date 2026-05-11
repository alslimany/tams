<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Orders\SyncInsuranceCancellationStatus;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AgencySetting;
use App\Models\Tenant as CentralTenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\Videcom\VidecomPnrParser;
use App\Services\Insurance\InsuranceProviderManager;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use SimpleXMLElement;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Enums\Unit;

use function Spatie\LaravelPdf\Support\pdf;

class OrderController extends Controller
{
    public function __construct(
        protected InsuranceProviderManager $insuranceProviderManager,
        protected SyncInsuranceCancellationStatus $syncInsuranceCancellationStatus,
    ) {}

    public function index(): Response
    {
        $orders = Order::query()
            ->with([
                'owner',
                'items:id,order_id,type,product_type,product_subtype,provider,provider_reference,ticket_number,total,total_amount,currency,status,item_details,product_details',
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
        $this->syncInsuranceCancellationItems($order);

        $order->load(['owner', 'items', 'statusLogs.user']);

        $walletTransactionUuids = $order->items
            ->flatMap(fn (OrderItem $item): array => [
                $item->wallet_transaction_id,
                data_get($item->item_details, 'provider_wallet_transaction_id'),
                data_get($item->item_details, 'provider_wallet_void_transaction_id'),
                data_get($item->item_details, 'refund.customer_wallet_transaction_id'),
                data_get($item->item_details, 'refund.penalty_wallet_transaction_id'),
                data_get($item->item_details, 'refund.provider_wallet_transaction_id'),
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $walletTransactions = WalletTransaction::query()
            ->whereIn('uuid', $walletTransactionUuids)
            ->get(['id', 'uuid', 'type', 'amount', 'meta', 'created_at'])
            ->keyBy('uuid');

        $itemTransactions = $order->items->map(function ($item) use ($walletTransactions): array {
            return [
                'order_item_id' => $item->id,
                'wallet_transaction' => $item->wallet_transaction_id
                    ? $walletTransactions->get($item->wallet_transaction_id)
                    : null,
                'provider_wallet_transaction' => data_get($item->item_details, 'provider_wallet_transaction_id')
                    ? $walletTransactions->get(data_get($item->item_details, 'provider_wallet_transaction_id'))
                    : null,
                'provider_wallet_void_transaction' => data_get($item->item_details, 'provider_wallet_void_transaction_id')
                    ? $walletTransactions->get(data_get($item->item_details, 'provider_wallet_void_transaction_id'))
                    : null,
                'refund_wallet_transaction' => data_get($item->item_details, 'refund.customer_wallet_transaction_id')
                    ? $walletTransactions->get(data_get($item->item_details, 'refund.customer_wallet_transaction_id'))
                    : null,
                'refund_penalty_transaction' => data_get($item->item_details, 'refund.penalty_wallet_transaction_id')
                    ? $walletTransactions->get(data_get($item->item_details, 'refund.penalty_wallet_transaction_id'))
                    : null,
                'provider_wallet_refund_transaction' => data_get($item->item_details, 'refund.provider_wallet_transaction_id')
                    ? $walletTransactions->get(data_get($item->item_details, 'refund.provider_wallet_transaction_id'))
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

    public function flightTicketPdf(Order $order, OrderItem $item): \Spatie\LaravelPdf\PdfBuilder
    {
        abort_unless($item->order_id === $order->id, 404);
        abort_unless((string) $item->type === 'flight' || (string) $item->product_type === 'flight', 404);

        $order->loadMissing('owner');

        $pnr = (array) $item->item_details;
        $filename = 'flight-ticket-'.preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($item->provider_reference ?: $order->number)).'.pdf';

        return pdf()
            ->view('pdf.flight-ticket', [
                'order' => $order,
                'item' => $item,
                'pnr' => $pnr,
                'itineraries' => $this->ticketPdfItineraries($item),
                'passengers' => (array) ($pnr['passengers'] ?? []),
                'contacts' => (array) ($pnr['contacts'] ?? []),
            ])
            ->format(Format::A4)
            ->margins(8, 8, 8, 8, Unit::Millimeter)
            ->inline($filename);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function ticketPdfItineraries(OrderItem $item): array
    {
        $pnr = (array) $item->item_details;
        $itineraries = (array) ($pnr['itineraries'] ?? []);

        if ($itineraries !== []) {
            return array_map(function (array $itinerary) use ($pnr): array {
                $itinerary['tickets'] = $this->ticketsForItinerary((array) ($pnr['tickets'] ?? []), $itinerary);

                return $itinerary;
            }, $itineraries);
        }

        return array_map(function (array $segment) use ($pnr): array {
            return [
                'itinerary_id' => $segment['itinerary_id'] ?? null,
                'airline_id' => $segment['airline_id'] ?? $pnr['iata'] ?? $pnr['airline_code'] ?? null,
                'flight_number' => $segment['flight_number'] ?? null,
                'class' => $segment['class'] ?? null,
                'cabin' => $segment['cabin'] ?? $segment['class'] ?? null,
                'class_band' => $segment['class_band'] ?? null,
                'class_band_display_name' => $segment['class_band_display_name'] ?? null,
                'date' => $segment['date'] ?? $segment['departure_date'] ?? null,
                'from' => $segment['from'] ?? $segment['origin'] ?? $segment['departure_airport'] ?? null,
                'to' => $segment['to'] ?? $segment['destination'] ?? $segment['arrival_airport'] ?? null,
                'departure' => $segment['departure'] ?? $segment['departure_time'] ?? null,
                'arrival' => $segment['arrival'] ?? $segment['arrival_time'] ?? null,
                'status' => $segment['status'] ?? null,
                'tickets' => (array) ($pnr['tickets'] ?? []),
            ];
        }, (array) ($item->product_details['segments'] ?? $pnr['segments'] ?? []));
    }

    /**
     * @param  array<int, array<string, mixed>>  $tickets
     * @param  array<string, mixed>  $itinerary
     * @return array<int, array<string, mixed>>
     */
    protected function ticketsForItinerary(array $tickets, array $itinerary): array
    {
        return array_values(array_filter($tickets, function (array $ticket) use ($itinerary): bool {
            $ticketSegmentNumber = $this->normalizedSegmentNumber($ticket['segment_number'] ?? null);
            $itinerarySegmentNumber = $this->normalizedSegmentNumber($itinerary['itinerary_id'] ?? null);

            if ($ticketSegmentNumber !== null && $itinerarySegmentNumber !== null && $ticketSegmentNumber === $itinerarySegmentNumber) {
                return true;
            }

            return $this->flightNumberMatches($ticket['flight_number'] ?? null, $itinerary)
                && (string) ($ticket['from'] ?? '') === (string) ($itinerary['from'] ?? '')
                && (string) ($ticket['to'] ?? '') === (string) ($itinerary['to'] ?? '');
        }));
    }

    /**
     * @param  array<string, mixed>  $itinerary
     */
    protected function flightNumberMatches(mixed $ticketFlightNumber, array $itinerary): bool
    {
        if (! is_string($ticketFlightNumber) && ! is_numeric($ticketFlightNumber)) {
            return false;
        }

        $compactTicketFlight = mb_strtoupper(preg_replace('/\s+/', '', (string) $ticketFlightNumber) ?? '');
        $compactItineraryFlight = mb_strtoupper(preg_replace('/\s+/', '', (string) ($itinerary['airline_id'] ?? '').(string) ($itinerary['flight_number'] ?? '')) ?? '');

        return $compactTicketFlight !== '' && $compactTicketFlight === $compactItineraryFlight;
    }

    protected function normalizedSegmentNumber(mixed $value): ?int
    {
        $number = (int) preg_replace('/^0+/', '', (string) $value);

        return $number > 0 ? $number : null;
    }

    protected function syncOrderItemsFromPnrQuery(Order $order): void
    {
        $order->loadMissing('items');

        $pnrGroups = $order->items
            ->filter(fn (OrderItem $item): bool => (string) $item->type !== 'insurance'
                && filled($item->provider_reference)
                && ! in_array($item->status, ['voided', 'refunded', 'cancellation', 'cancelled'], true))
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

    protected function syncInsuranceCancellationItems(Order $order): void
    {
        $order->loadMissing('items');

        $insuranceItems = $order->items->filter(
            fn (OrderItem $item): bool => (string) $item->type === 'insurance'
                && (string) $item->status === 'cancellation'
        );

        foreach ($insuranceItems as $item) {
            try {
                $this->syncInsuranceCancellationStatus->execute($item);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
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
