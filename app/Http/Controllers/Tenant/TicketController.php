<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\ApplyFinancialSourceAndCommission;
use App\Actions\Finance\CreateAirlineTransactions;
use App\Actions\Finance\CreateOrderFromBookingData;
use App\Actions\Finance\DetermineFinancialSource;
use App\Actions\Finance\InitializeTenantLedger;
use App\Actions\Finance\PostToLedger;
use App\Actions\Finance\ProcessWalletTransactions;
use App\DTOs\Videcom\OrderItemData;
use App\DTOs\Videcom\ParsedBookingData;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Controller;
use App\Jobs\UpdateAirlineBalanceJob;
use App\Models\Tenant\AgencySetting;
use App\Models\Tenant as CentralTenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Airline\AgencyProviderResolver;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\Videcom\VidecomPnrParser;
use App\Services\Commission\TenantProviderCommissionCalculator;
use App\Services\Videcom\VidecomOrderParser;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use SimpleXMLElement;

class TicketController extends Controller
{
    public function __construct(
        protected TenantProviderCommissionCalculator $tenantProviderCommissionCalculator,
        protected AgencyProviderResolver $providerResolver,
    ) {}

    public function issue(Request $request, Order $booking): RedirectResponse
    {
        $booking->loadMissing('items');

        $firstItem = $booking->items->first();
        if (! $firstItem) {
            return back()->with('error', 'No flight item found for this booking.');
        }

        $pnr = (string) ($firstItem->provider_reference ?: $booking->payment_reference);
        $airlineCode = (string) data_get($firstItem->item_details, 'airline_code', '');

        // Resolve the correct provider based on agency settings
        $resolved = $this->providerResolver->resolve($airlineCode);
        $providerConfig = $resolved['provider'];

        if (! $providerConfig) {
            return back()->with('error', 'No active provider found for this booking.');
        }

        $provider = ProviderFactory::make($providerConfig);

        $issuer = $request->user();
        if (! $issuer instanceof User) {
            return back()->with('error', 'An authenticated user is required to issue a ticket.');
        }

        try {
            $this->assertWalletBalanceBeforeIssue($booking, $issuer);
        } catch (InsufficientWalletBalanceException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $paymentType = strtolower((string) $request->input('payment_type', 'airline_token'));

        try {
            $issuedOrder = DB::transaction(function () use ($booking, $provider, $pnr, $paymentType, $issuer): Order {
                // Step 1: Issue ticket via the airline provider.
                $issueResult = $provider->issueTicket($pnr, [
                    'type' => $paymentType,
                    'user' => $issuer,
                ]);

                $pnrResponse = method_exists($provider, 'queryPnr')
                    ? $provider->queryPnr($pnr)
                    : $provider->retrieveBooking($pnr);

                $pnrXml = $this->toXml($pnrResponse) ?? $this->extractIssueXml($issueResult);
                if (! $pnrXml) {
                    throw new \RuntimeException('Ticket issuance completed, but provider returned invalid PNR payload.');
                }

                $parsedPnr = VidecomPnrParser::parse($pnrXml);
                $formattedPnr = VidecomPnrParser::formatForOrderDetails($pnrXml);

                /** @var ParsedBookingData $parsedBookingData */
                $parsedBookingData = $this->extractParsedBookingData($issueResult, $pnrXml, $pnrResponse);
                $parsedBookingData->paymentMethod = $paymentType;
                $parsedBookingData->paymentReference = $parsedBookingData->paymentReference ?: $pnr;

                // Step 2: Create order and order items (without financial source processing yet).
                $order = app(CreateOrderFromBookingData::class)->execute($parsedBookingData);
                $order->update([
                    'parent_id' => $booking->id,
                ]);

                // Step 3: Determine financial source and set commission on each order item.
                // Step 4: Save order items (they now have commission and financial source flag).
                $this->applyFinancialSourceAndCommission($order);

                // Collect the distinct financial sources across all order items.
                $financialSources = $order->items
                    ->pluck('item_details.financial_source')
                    ->filter(fn ($value): bool => is_string($value) && $value !== '')
                    ->unique();

                $resolvedPaymentMethod = match (true) {
                    $financialSources->contains('master_agency_supply') && $financialSources->count() === 1 => 'default_agency_supply',
                    $financialSources->contains('master_agency_supply') && $financialSources->contains('own_credentials') => 'mixed_supply',
                    $financialSources->contains('own_credentials') && $financialSources->count() === 1 => 'own_credentials',
                    default => $paymentType,
                };

                $order->update(['payment_method' => $resolvedPaymentMethod]);

                // Step 5: If financial source = 'master_agency_supply', withdraw from wallet
                //         and record commission payable for later settlement.
                if ($financialSources->contains('master_agency_supply')) {
                    app(ProcessWalletTransactions::class)->execute($order, $issuer);
                }

                // Step 6: If financial source = 'own_credentials', record airline account debits.
                if ($financialSources->contains('own_credentials')) {
                    app(CreateAirlineTransactions::class)->execute($order);
                }

                // Step 7: Create ledger entries.
                app(InitializeTenantLedger::class)->execute((string) $order->currency);
                app(PostToLedger::class)->execute($order, includeOwnCredentials: true);

                $this->syncBookingIssueSnapshot($booking, $order, $parsedPnr, $formattedPnr);

                // Step 8: Log order status change.
                $order->statusLogs()->create([
                    'old_status' => (string) $booking->status,
                    'new_status' => 'issued',
                    'user_id' => $issuer->id,
                    'comment' => "Ticket issued and financials posted for PNR {$parsedBookingData->pnr}.",
                ]);

                // Step 9: Commit (handled by DB::transaction returning).
                return $order->fresh('items');
            });

            $this->dispatchDelayedAirlineBalanceUpdate($providerConfig);

            return redirect()
                ->route('tickets.completed', ['booking' => $issuedOrder->id, 'order' => $issuedOrder->id])
                ->with('success', 'Ticket issued successfully.');
        } catch (ConnectionException $exception) {
            report($exception);

            // Rollback is automatic; void the PNR to avoid orphaned tickets.
            $this->voidProviderPnrSafely($provider, $pnr);

            return back()->with('error', 'Airline ticket issuance timed out. Please try again.');
        } catch (\Throwable $exception) {
            report($exception);

            // Rollback is automatic; void the PNR to avoid orphaned tickets.
            $this->voidProviderPnrSafely($provider, $pnr);

            return back()->with('error', 'Failed to issue ticket and post financial transactions. The PNR void command was attempted.');
        }
    }

    protected function extractIssueXml(mixed $issueResult): ?SimpleXMLElement
    {
        if ($issueResult instanceof SimpleXMLElement) {
            return $issueResult;
        }

        if (is_array($issueResult)) {
            return $this->toXml($issueResult['xml'] ?? null);
        }

        return $this->toXml($issueResult);
    }

    protected function extractParsedBookingData(
        mixed $issueResult,
        SimpleXMLElement $pnrXml,
        mixed $pnrResponse,
    ): ParsedBookingData {
        if (is_array($issueResult) && is_array($issueResult['parsed'] ?? null)) {
            $parsed = $issueResult['parsed'];
            $items = array_map(
                fn (array $item): OrderItemData => new OrderItemData(
                    passengerName: (string) data_get($item, 'passenger_name', 'Passenger'),
                    segments: array_values((array) data_get($item, 'segments', [])),
                    fare: (float) data_get($item, 'fare', 0),
                    taxes: (float) data_get($item, 'taxes', 0),
                    total: (float) data_get($item, 'total', 0),
                    ticketNumber: data_get($item, 'ticket_number'),
                    commission: (float) data_get($item, 'commission', 0),
                    airlineCode: data_get($item, 'airline_code'),
                    currency: (string) data_get($item, 'currency', data_get($parsed, 'currency', 'USD')),
                ),
                array_values((array) data_get($parsed, 'items', [])),
            );

            return new ParsedBookingData(
                pnr: (string) data_get($parsed, 'pnr', ''),
                grandTotal: (float) data_get($parsed, 'grand_total', 0),
                currency: (string) data_get($parsed, 'currency', 'USD'),
                paymentMethod: (string) data_get($parsed, 'payment_method', 'invoice'),
                paymentReference: data_get($parsed, 'payment_reference'),
                items: $items,
            );
        }

        return app(VidecomOrderParser::class)->parse($pnrXml->asXML() ?: (string) $pnrResponse);
    }

    public function completed(Request $request, Order $booking): InertiaResponse
    {
        $booking->loadMissing('items');

        return Inertia::render('Tenant/Bookings/Completed', [
            'booking' => $this->formatOrderForBooking($booking),
            'order' => $booking->loadMissing('owner', 'items'),
        ]);
    }

    public function void(Request $request, Order $booking, string $ticket): RedirectResponse
    {
        $booking->loadMissing('items');

        $item = $booking->items()->whereKey($ticket)->first();
        if (! $item) {
            return back()->with('error', 'Ticket item not found.');
        }

        $voidable = data_get($item->item_details, 'is_voidable', true);
        if (! $voidable) {
            return back()->with('error', 'This PNR cannot be voided. Please use refund instead.');
        }

        $issueDate = $this->resolveIssueDateForVoid($item);
        if (! $issueDate || ! $issueDate->isSameDay(now())) {
            return back()->with('error', 'PNR can only be voided on the same issue date. Please use refund flow.');
        }

        $pnr = (string) ($item->provider_reference ?: $booking->payment_reference);
        if ($pnr === '') {
            return back()->with('error', 'PNR reference not found for this order item.');
        }

        $providerConfig = $this->resolveProviderForTicketAction($item);

        if (! $providerConfig) {
            return back()->with('error', 'No active provider found for this booking.');
        }

        $provider = ProviderFactory::make($providerConfig);

        try {
            $voidResponse = $provider->void($pnr);
        } catch (ConnectionException $exception) {
            report($exception);

            return back()->with('error', 'Airline void request timed out. Please try again.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Failed to void PNR with the airline provider.');
        }

        $voidXml = $this->toXml($voidResponse);
        if (! $voidXml) {
            return back()->with('error', 'Airline returned invalid PNR payload after void command.');
        }

        $voidSnapshot = VidecomPnrParser::formatForOrderDetails($voidXml);
        if (collect($voidSnapshot['tickets'] ?? [])->isNotEmpty()) {
            return back()->with('error', 'PNR still contains tickets after void command. Void was not completed.');
        }

        $voidedItems = $booking->items
            ->filter(fn (OrderItem $orderItem): bool => (string) ($orderItem->provider_reference ?: $booking->payment_reference) === $pnr)
            ->values();

        $voidedAmount = (float) $voidedItems->sum(fn (OrderItem $orderItem): float => (float) $orderItem->total);
        $oldStatus = (string) $booking->status;

        DB::transaction(function () use ($booking, $voidedItems, $pnr, $voidedAmount, $oldStatus, $request): void {
            foreach ($voidedItems as $orderItem) {
                $walletTransactionId = $this->depositVoidAmountToWallet($booking, $orderItem, $pnr, $request->user());

                $orderItem->update([
                    // Keep original PNR reference stable for traceability after void.
                    'provider_reference' => (string) ($orderItem->provider_reference ?: $pnr),
                    // Keep original ticket number for historical visibility after void.
                    'ticket_number' => $orderItem->ticket_number,
                    'wallet_transaction_id' => $walletTransactionId ?: $orderItem->wallet_transaction_id,
                    'status' => 'voided',
                    'remaining' => 0,
                ]);
            }

            $booking->update([
                'status' => 'voided',
                'amount_refunded' => (float) $booking->amount_refunded + $voidedAmount,
            ]);

            $booking->statusLogs()->create([
                'old_status' => $oldStatus,
                'new_status' => 'voided',
                'user_id' => $request->user()?->id,
                'comment' => "PNR {$pnr} voided successfully and {$voidedAmount} {$booking->currency} refunded to wallet.",
            ]);
        });

        $this->dispatchDelayedAirlineBalanceUpdate($providerConfig);

        return back()->with('success', 'Ticket voided successfully.');
    }

    public function refund(Order $booking, string $ticket): RedirectResponse
    {
        $item = $booking->items()->whereKey($ticket)->first();
        if (! $item) {
            return back()->with('error', 'Ticket item not found.');
        }

        $ticketNumber = (string) ($item->ticket_number ?? '');
        if ($ticketNumber === '') {
            return back()->with('error', 'Ticket number is required for refund operation.');
        }

        $airlineCode = (string) data_get($item->item_details, 'airline_code', data_get($item->item_details, 'iata', ''));
        $providerConfig = TenantProvider::query()
            ->where('is_active', true)
            ->when($airlineCode !== '', fn ($query) => $query->where('airline_code', $airlineCode))
            ->first();

        if (! $providerConfig) {
            return back()->with('error', 'No active provider found for this booking.');
        }

        $provider = ProviderFactory::make($providerConfig);

        try {
            $provider->refund($ticketNumber);
        } catch (ConnectionException $exception) {
            report($exception);

            return back()->with('error', 'Airline refund request timed out. Please try again.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Failed to refund ticket with the airline provider.');
        }

        $this->dispatchDelayedAirlineBalanceUpdate($providerConfig);

        $item->update(['status' => 'refunded']);
        $booking->update([
            'status' => 'refunded',
            'amount_refunded' => (float) $booking->grand_total,
        ]);

        return back()->with('success', 'Refund recorded successfully.');
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

    protected function formatOrderForBooking(Order $order): array
    {
        $order->loadMissing('items');
        $firstItem = $order->items->first();

        $providerCode = (string) data_get($firstItem?->item_details, 'airline_code', '');
        $provider = $providerCode === ''
            ? null
            : TenantProvider::query()->where('airline_code', $providerCode)->first(['id', 'airline_name', 'airline_code']);

        $segments = collect((array) data_get($firstItem?->item_details, 'segments', []))
            ->map(function (array $segment, int $index): array {
                return [
                    'id' => data_get($segment, 'id', $index + 1),
                    'flight_number' => (string) data_get($segment, 'flight_number', ''),
                    'origin_airport' => (string) data_get($segment, 'departure_airport', data_get($segment, 'origin', '')),
                    'destination_airport' => (string) data_get($segment, 'arrival_airport', data_get($segment, 'destination', '')),
                    'departure_time' => (string) data_get($segment, 'departure_time', data_get($segment, 'date', '')),
                    'arrival_time' => (string) data_get($segment, 'arrival_time', ''),
                ];
            })
            ->values();

        $passengers = collect((array) data_get($firstItem?->item_details, 'passengers', []))
            ->map(function (array $passenger, int $index): array {
                return [
                    'id' => data_get($passenger, 'id', $index + 1),
                    'first_name' => (string) data_get($passenger, 'first_name', ''),
                    'last_name' => (string) data_get($passenger, 'last_name', ''),
                    'type' => (string) data_get($passenger, 'type', 'adult'),
                ];
            })
            ->values();

        $tickets = $order->items
            ->filter(fn (OrderItem $item): bool => filled($item->ticket_number))
            ->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'ticket_number' => $item->ticket_number,
                'status' => $item->status,
                'issued_at' => optional($item->updated_at)->toISOString(),
            ])
            ->values();

        return [
            'id' => $order->id,
            'pnr' => (string) ($firstItem?->provider_reference ?: $order->payment_reference),
            'status' => $order->status,
            'total_price' => (float) $order->grand_total,
            'currency' => $order->currency,
            'provider' => $provider,
            'flight_segments' => $segments,
            'passengers' => $passengers,
            'customer' => [
                'first_name' => (string) data_get($order->contact, 'first_name', ''),
                'last_name' => (string) data_get($order->contact, 'last_name', ''),
                'email' => (string) data_get($order->contact, 'email', ''),
            ],
            'tickets' => $tickets,
        ];
    }

    protected function resolveIssueDateForVoid(OrderItem $item): ?Carbon
    {
        $issueDate = (string) data_get($item->item_details, 'tickets.0.issue_date', '');
        if ($issueDate === '') {
            $issueDate = (string) data_get($item->item_details, 'payments.0.date', '');
        }

        if ($issueDate === '') {
            return null;
        }

        try {
            return Carbon::parse($issueDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function resolveProviderForTicketAction(OrderItem $item): ?TenantProvider
    {
        $airlineCode = (string) data_get(
            $item->item_details,
            'airline_code',
            data_get($item->item_details, 'iata', data_get($item->product_details, 'airline_code', '')),
        );
        $airlineCode = strtoupper(trim($airlineCode));
        $providerId = data_get($item->item_details, 'financial_provider_id');

        $sourceTenantId = (string) (
            data_get($item->item_details, 'financial_source_tenant_id')
            ?: data_get($item->item_details, 'default_agency_tenant_id', '')
        );

        if ($sourceTenantId === '') {
            $orderPaymentMethod = (string) ($item->relationLoaded('order')
                ? data_get($item->order, 'payment_method', '')
                : (string) $item->order()->value('payment_method'));

            $isMasterSupply = (string) data_get($item->item_details, 'financial_source') === 'master_agency_supply'
                || $orderPaymentMethod === 'default_agency_supply';

            if ($isMasterSupply) {
                $sourceTenantId = (string) (
                    AgencySetting::current()->default_agency_tenant_id
                    ?: CentralTenant::getDefaultAgency()?->id
                    ?: ''
                );
            }
        }

        if ($sourceTenantId !== '') {
            $sourceTenant = CentralTenant::query()->find($sourceTenantId);

            if ($sourceTenant) {
                return $sourceTenant->run(function () use ($airlineCode, $providerId): ?TenantProvider {
                    if (! empty($providerId)) {
                        $provider = TenantProvider::query()->whereKey($providerId)->where('is_active', true)->first();

                        if ($provider) {
                            return $provider;
                        }
                    }

                    return TenantProvider::query()
                        ->where('is_active', true)
                        ->when($airlineCode !== '', fn ($query) => $query->where('airline_code', $airlineCode))
                        ->first();
                });
            }
        }

        if (! empty($providerId)) {
            $provider = TenantProvider::query()->whereKey($providerId)->where('is_active', true)->first();

            if ($provider) {
                return $provider;
            }
        }

        return TenantProvider::query()
            ->where('is_active', true)
            ->when($airlineCode !== '', fn ($query) => $query->where('airline_code', $airlineCode))
            ->first();
    }

    protected function depositVoidAmountToWallet(Order $booking, OrderItem $orderItem, string $pnr, ?User $user): ?string
    {
        $refundTarget = $this->resolveVoidRefundTarget($orderItem, $user);
        if (! $refundTarget) {
            return null;
        }

        $walletHolder = $refundTarget['holder'];
        $amount = $refundTarget['amount'];

        if ($amount <= 0) {
            return null;
        }

        $wallet = $walletHolder->getOrCreateCurrencyWallet($booking->currency);

        $transaction = $wallet->depositFloat($amount, [
            'order_id' => $booking->id,
            'order_item_id' => $orderItem->id,
            'type' => 'ticket_void_refund',
            'description' => "Void refund for PNR {$pnr}",
        ]);

        return $transaction?->uuid;
    }

    /**
     * @return array{holder: User, amount: float}|null
     */
    protected function resolveVoidRefundTarget(OrderItem $orderItem, ?User $fallback): ?array
    {
        $originalWalletTxUuid = (string) ($orderItem->wallet_transaction_id ?? '');

        if ($originalWalletTxUuid !== '') {
            $originalWalletTx = WalletTransaction::query()
                ->where('uuid', $originalWalletTxUuid)
                ->first(['wallet_id', 'amount']);

            if ($originalWalletTx?->wallet_id) {
                $walletOwner = DB::table('wallets')
                    ->where('id', (int) $originalWalletTx->wallet_id)
                    ->first(['holder_type', 'holder_id']);

                if ($walletOwner && (string) $walletOwner->holder_type === User::class) {
                    $resolvedUser = User::query()->find((int) $walletOwner->holder_id);

                    if ($resolvedUser) {
                        // Amount is stored in minor units and issue withdraw is negative.
                        $originalWithdrawAmount = abs((float) $originalWalletTx->amount) / 100;
                        $fallbackAmount = (float) $orderItem->total;

                        return [
                            'holder' => $resolvedUser,
                            'amount' => $originalWithdrawAmount > 0 ? $originalWithdrawAmount : $fallbackAmount,
                        ];
                    }
                }
            }
        }

        if ($fallback) {
            return [
                'holder' => $this->resolveAgencyWalletHolder($fallback),
                'amount' => (float) $orderItem->total,
            ];
        }

        return null;
    }

    protected function resolveAgencyWalletHolder(User $fallback): User
    {
        return User::query()
            ->whereIn('role', ['admin', 'manager'])
            ->orderBy('id')
            ->first() ?? $fallback;
    }

    protected function dispatchDelayedAirlineBalanceUpdate(TenantProvider $provider): void
    {
        UpdateAirlineBalanceJob::dispatch($provider->id)
            ->delay(now()->addMinutes(10));
    }

    protected function applyFinancialSourceAndCommission(Order $order): void
    {
        app(ApplyFinancialSourceAndCommission::class)->execute($order);
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    protected function assertWalletBalanceBeforeIssue(Order $booking, User $issuer): void
    {
        $booking->loadMissing('items');

        $requiredByCurrency = [];
        $financialSourceAction = app(DetermineFinancialSource::class);

        foreach ($booking->items as $item) {
            $airlineCode = (string) data_get($item->item_details, 'airline_code', data_get($item->product_details, 'airline_code', ''));
            $currency = strtoupper((string) $item->currency);
            $source = $financialSourceAction->execute($airlineCode, $currency);

            if (! $source->usesMasterAgencySupply()) {
                continue;
            }

            $requiredByCurrency[$currency] = round(
                ((float) ($requiredByCurrency[$currency] ?? 0)) + (float) ($item->total_amount ?? $item->total ?? 0),
                2,
            );
        }

        if ($requiredByCurrency === []) {
            return;
        }

        app(ProcessWalletTransactions::class)->assertCanIssueForAmounts($requiredByCurrency, $issuer);
    }

    protected function voidProviderPnrSafely(mixed $provider, string $pnr): void
    {
        if (! is_object($provider) || ! is_callable([$provider, 'void'])) {
            return;
        }

        try {
            $provider->void($pnr);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $parsedPnr
     * @param  array<string, mixed>  $formattedPnr
     */
    protected function syncBookingIssueSnapshot(Order $booking, Order $issuedOrder, array $parsedPnr, array $formattedPnr): void
    {
        $booking->loadMissing('items');

        $ticketsByNumber = collect($parsedPnr['tickets'] ?? [])->groupBy('ticket_number');
        $commissionAllocations = $this->allocateCommissionAcrossItems(
            $booking->items,
            (float) $issuedOrder->items->sum(fn (OrderItem $item): float => (float) $item->agent_commission),
        );

        foreach ($booking->items->values() as $index => $item) {
            $ticketNumber = (string) ($item->ticket_number ?? '');
            $ticketRows = $ticketNumber !== '' ? $ticketsByNumber->get($ticketNumber) : null;

            if (! $ticketRows instanceof Collection || $ticketRows->isEmpty()) {
                $ticketRows = $ticketsByNumber->first();
            }

            $ticketData = $ticketRows?->first() ?? [];
            $resolvedTicketNumber = (string) ($ticketData['ticket_number'] ?? $ticketNumber);

            $details = $formattedPnr;
            $details['pnr_synced_at'] = now()->toIso8601String();
            $details['commission'] = [
                'flight_type' => (string) data_get($formattedPnr, 'flight_type', ''),
                'fare_total' => (float) data_get($formattedPnr, 'total_fare', 0),
                'agent_commission' => $commissionAllocations[$index] ?? 0.0,
            ];

            $item->update([
                'provider_reference' => (string) ($parsedPnr['rloc'] ?? $item->provider_reference),
                'ticket_number' => $resolvedTicketNumber !== '' ? $resolvedTicketNumber : $item->ticket_number,
                'item_details' => $details,
                'status' => 'issued',
                'remaining' => 0,
                'paid' => (float) $item->total,
                'agent_commission' => $commissionAllocations[$index] ?? 0.0,
                'net_commission' => $commissionAllocations[$index] ?? 0.0,
            ]);
        }

        $booking->update([
            'status' => 'confirmed',
            'issued_at' => now(),
            'amount_paid' => (float) $booking->grand_total,
        ]);
    }

    /**
     * @return array<int, float>
     */
    protected function allocateCommissionAcrossItems(Collection $items, float $commissionTotal): array
    {
        if ($commissionTotal <= 0 || $items->isEmpty()) {
            return $items->map(fn (): float => 0.0)->values()->all();
        }

        $items = $items->values();
        $itemsTotal = (float) $items->sum(fn (OrderItem $item): float => (float) $item->total);
        if ($itemsTotal <= 0) {
            return $items->map(fn (): float => 0.0)->values()->all();
        }

        $allocations = [];
        $allocated = 0.0;

        foreach ($items as $index => $item) {
            if ($index === $items->count() - 1) {
                $allocations[] = round($commissionTotal - $allocated, 2);

                continue;
            }

            $portion = round($commissionTotal * (((float) $item->total) / $itemsTotal), 2);
            $allocations[] = $portion;
            $allocated += $portion;
        }

        return $allocations;
    }
}
