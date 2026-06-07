<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\ApplyFinancialSourceAndCommission;
use App\Actions\Finance\CreateOrderFromBookingData;
use App\Actions\Finance\DetermineFinancialSource;
use App\Actions\Finance\InitializeTenantLedger;
use App\Actions\Finance\PostToLedger;
use App\Actions\Finance\ProcessProviderWalletTransactions;
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
use App\Notifications\Orders\OrderContact;
use App\Notifications\Orders\TicketCancelled;
use App\Notifications\Orders\TicketIssued;
use App\Notifications\Orders\TicketVoided;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Airline\AgencyProviderResolver;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\Videcom\VidecomPnrParser;
use App\Services\Commission\TenantProviderCommissionCalculator;
use App\Services\Videcom\VidecomOrderParser;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
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
        protected LedgerPostingService $ledgerPostingService,
    ) {}

    public function issue(Request $request, Order $booking): RedirectResponse|JsonResponse
    {
        $booking->loadMissing('items');

        $firstItem = $booking->items->first();
        if (! $firstItem) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'No flight item found for this booking.'], 404)
                : back()->with('error', 'No flight item found for this booking.');
        }

        $pnr = (string) ($firstItem->provider_reference ?: $booking->payment_reference);
        $airlineCode = (string) data_get($firstItem->item_details, 'airline_code', '');

        // Resolve the correct provider based on agency settings
        $resolved = $this->providerResolver->resolve($airlineCode);
        $providerConfig = $resolved['provider'];

        if (! $providerConfig) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'No active provider found for this booking.'], 422)
                : back()->with('error', 'No active provider found for this booking.');
        }

        $provider = ProviderFactory::make($providerConfig);

        $issuer = $request->user();
        if (! $issuer instanceof User) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'An authenticated user is required to issue a ticket.'], 401)
                : back()->with('error', 'An authenticated user is required to issue a ticket.');
        }

        try {
            $this->assertWalletBalanceBeforeIssue($booking, $issuer, $providerConfig);
        } catch (InsufficientWalletBalanceException $exception) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $exception->getMessage()], 402)
                : back()->with('error', $exception->getMessage());
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

                // Enrich each issued order item with fare breakdown from the parsed PNR so that
                // ApplyFinancialSourceAndCommission can resolve base fare and tax correctly.
                $this->enrichItemsWithFareData($order, $formattedPnr);

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

                // Step 6: If financial source = 'own_credentials', record provider wallet deductions.
                if ($financialSources->contains('own_credentials')) {
                    app(ProcessProviderWalletTransactions::class)->execute($order);
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

            $contact = OrderContact::fromOrder($issuedOrder);
            if (filled($contact->email) || filled($contact->phone)) {
                $contact->notify(new TicketIssued($issuedOrder, $issuedOrder->items->first()));
            }

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Ticket issued successfully.',
                    'data' => $issuedOrder->refresh()->load('items'),
                ], 201);
            }

            return redirect()
                ->route('tickets.completed', ['booking' => $issuedOrder->id, 'order' => $issuedOrder->id])
                ->with('success', 'Ticket issued successfully.');
        } catch (ConnectionException $exception) {
            report($exception);

            // Rollback is automatic; void the PNR to avoid orphaned tickets.
            $this->voidProviderPnrSafely($provider, $pnr);

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'Airline ticket issuance timed out. Please try again.'], 503)
                : back()->with('error', 'Airline ticket issuance timed out. Please try again.');
        } catch (\Throwable $exception) {
            report($exception);

            // Rollback is automatic; void the PNR to avoid orphaned tickets.
            $this->voidProviderPnrSafely($provider, $pnr);

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'Failed to issue ticket and post financial transactions. The PNR void command was attempted.'], 500)
                : back()->with('error', 'Failed to issue ticket and post financial transactions. The PNR void command was attempted.');
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

    public function void(Request $request, Order $booking, string $ticket): RedirectResponse|JsonResponse
    {
        $booking->loadMissing('items');

        $item = $booking->items()->whereKey($ticket)->first();
        if (! $item) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'Ticket item not found.'], 404)
                : back()->with('error', 'Ticket item not found.');
        }

        $voidable = data_get($item->item_details, 'is_voidable', true);
        if (! $voidable) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'This PNR cannot be voided. Please use refund instead.'], 422)
                : back()->with('error', 'This PNR cannot be voided. Please use refund instead.');
        }

        $issueDate = $this->resolveIssueDateForVoid($item, $booking);
        if (! $issueDate || ! $issueDate->isSameDay(now())) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'PNR can only be voided on the same issue date. Please use refund flow.'], 422)
                : back()->with('error', 'PNR can only be voided on the same issue date. Please use refund flow.');
        }

        $pnr = (string) ($item->provider_reference ?: $booking->payment_reference);
        if ($pnr === '') {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'PNR reference not found for this order item.'], 422)
                : back()->with('error', 'PNR reference not found for this order item.');
        }

        $providerConfig = $this->resolveProviderForTicketAction($item);

        if (! $providerConfig) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'No active provider found for this booking.'], 422)
                : back()->with('error', 'No active provider found for this booking.');
        }

        $provider = ProviderFactory::make($providerConfig);

        try {
            $voidResponse = $provider->void($pnr);
        } catch (ConnectionException $exception) {
            report($exception);

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'Airline void request timed out. Please try again.'], 503)
                : back()->with('error', 'Airline void request timed out. Please try again.');
        } catch (\Throwable $exception) {
            report($exception);

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'Failed to void PNR with the airline provider.'], 500)
                : back()->with('error', 'Failed to void PNR with the airline provider.');
        }

        $voidXml = $this->toXml($voidResponse);
        if (! $voidXml) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'Airline returned invalid PNR payload after void command.'], 502)
                : back()->with('error', 'Airline returned invalid PNR payload after void command.');
        }

        $voidSnapshot = VidecomPnrParser::formatForOrderDetails($voidXml);
        if (collect($voidSnapshot['tickets'] ?? [])->isNotEmpty()) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'PNR still contains tickets after void command. Void was not completed.'], 422)
                : back()->with('error', 'PNR still contains tickets after void command. Void was not completed.');
        }

        $voidedItems = $booking->items
            ->filter(fn (OrderItem $orderItem): bool => (string) ($orderItem->provider_reference ?: $booking->payment_reference) === $pnr)
            ->values();

        $voidedAmount = (float) $voidedItems->sum(fn (OrderItem $orderItem): float => (float) $orderItem->total);
        $oldStatus = (string) $booking->status;

        DB::transaction(function () use ($booking, $voidedItems, $pnr, $voidedAmount, $oldStatus, $request): void {
            foreach ($voidedItems as $orderItem) {
                $walletTransactionId = $this->depositVoidAmountToWallet($booking, $orderItem, $pnr, $request->user());
                $providerWalletReversalId = $this->depositVoidAmountToProviderWallet($orderItem, $pnr);
                $itemDetails = (array) $orderItem->item_details;

                if ($providerWalletReversalId !== null) {
                    $itemDetails['provider_wallet_void_transaction_id'] = $providerWalletReversalId;
                }

                // Post ledger reversal for this voided item.
                $this->postVoidLedgerReversal($booking, $orderItem);

                $orderItem->update([
                    // Keep original PNR reference stable for traceability after void.
                    'provider_reference' => (string) ($orderItem->provider_reference ?: $pnr),
                    // Keep original ticket number for historical visibility after void.
                    'ticket_number' => $orderItem->ticket_number,
                    'wallet_transaction_id' => $walletTransactionId ?: $orderItem->wallet_transaction_id,
                    'item_details' => $itemDetails,
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

        $contact = OrderContact::fromOrder($booking);
        if (filled($contact->email) || filled($contact->phone)) {
            $contact->notify(new TicketVoided($booking, $item));
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Ticket voided successfully.',
                'data' => [
                    'booking_id' => $booking->id,
                    'status' => 'voided',
                    'amount_refunded' => (float) $booking->fresh()?->amount_refunded,
                    'currency' => $booking->currency,
                ],
            ]);
        }

        return back()->with('success', 'Ticket voided successfully.');
    }

    /**
     * Return a refund quote (penalties + net refund amount) for a ticket item.
     * This is a read-only JSON endpoint — no state is changed.
     */
    public function refundQuote(Request $request, Order $booking, string $ticket): \Illuminate\Http\JsonResponse
    {
        $item = $booking->items()->whereKey($ticket)->first();
        if (! $item) {
            return response()->json(['error' => 'Ticket item not found.'], 404);
        }

        $pnr = (string) ($item->provider_reference ?: $booking->payment_reference);
        if ($pnr === '') {
            return response()->json(['error' => 'PNR reference not found for this order item.'], 422);
        }

        $providerConfig = $this->resolveProviderForTicketAction($item);
        if (! $providerConfig) {
            return response()->json(['error' => 'No active provider found for this booking.'], 422);
        }

        $provider = ProviderFactory::make($providerConfig);

        // Segment count from stored itineraries.
        $itineraries = data_get($item->item_details, 'itineraries', []);
        $segmentCount = max(count($itineraries), 1);

        try {
            $quote = $provider->refundQuote($pnr, $segmentCount);
        } catch (ConnectionException $exception) {
            report($exception);

            return response()->json(['error' => 'Airline refund quote request timed out. Please try again.'], 503);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to fetch refund quote from airline provider.'], 500);
        }

        return response()->json($quote);
    }

    public function refund(Request $request, Order $booking, string $ticket): RedirectResponse|JsonResponse
    {
        $item = $booking->items()->whereKey($ticket)->first();
        if (! $item) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'Ticket item not found.'], 404)
                : back()->with('error', 'Ticket item not found.');
        }

        $ticketNumber = (string) ($item->ticket_number ?? '');
        if ($ticketNumber === '') {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'Ticket number is required for refund operation.'], 422)
                : back()->with('error', 'Ticket number is required for refund operation.');
        }

        $pnr = (string) ($item->provider_reference ?: $booking->payment_reference);
        if ($pnr === '') {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'PNR reference not found for this order item.'], 422)
                : back()->with('error', 'PNR reference not found for this order item.');
        }

        $providerConfig = $this->resolveProviderForTicketAction($item);
        if (! $providerConfig) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'No active provider found for this booking.'], 422)
                : back()->with('error', 'No active provider found for this booking.');
        }

        $provider = ProviderFactory::make($providerConfig);

        $itineraries = data_get($item->item_details, 'itineraries', []);
        $segmentCount = max(count($itineraries), 1);

        // penalty_amount comes from the quote shown to the user in the modal.
        $penaltyAmount = round((float) $request->input('penalty_amount', 0), 2);
        // refund_amount is the net amount the airline will credit back to our wallet.
        $refundAmount = round((float) $request->input('refund_amount', 0), 2);

        try {
            $refundResult = $provider->refund($pnr, $segmentCount, $penaltyAmount);
        } catch (ConnectionException $exception) {
            report($exception);

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'Airline refund request timed out. Please try again.'], 503)
                : back()->with('error', 'Airline refund request timed out. Please try again.');
        } catch (\Throwable $exception) {
            report($exception);

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'Failed to refund ticket with the airline provider.'], 500)
                : back()->with('error', 'Failed to refund ticket with the airline provider.');
        }

        if (! ($refundResult['success'] ?? false)) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'Airline rejected the refund request. Response: '.($refundResult['raw_response'] ?? 'unknown')], 422)
                : back()->with('error', 'Airline rejected the refund request. Response: '.($refundResult['raw_response'] ?? 'unknown'));
        }

        DB::transaction(function () use ($booking, $item, $ticketNumber, $penaltyAmount, $refundAmount, $refundResult): void {
            $walletResult = $this->depositRefundAmountToWallet($booking, $item, $ticketNumber, $penaltyAmount, request()->user());
            $providerRefundTransactionId = $this->depositRefundAmountToProviderWallet($item, $ticketNumber);

            $itemDetails = (array) $item->item_details;
            data_set($itemDetails, 'refund.customer_wallet_transaction_id', $walletResult['refund_transaction_id']);
            data_set($itemDetails, 'refund.penalty_wallet_transaction_id', $walletResult['penalty_transaction_id']);
            data_set($itemDetails, 'refund.gross_refund_amount', $walletResult['gross_refund_amount']);
            data_set($itemDetails, 'refund.penalty_amount', $walletResult['penalty_amount']);
            data_set($itemDetails, 'refund.net_refund_amount', $walletResult['net_refund_amount']);
            data_set($itemDetails, 'refund.airline_refund_amount', $refundAmount);
            data_set($itemDetails, 'refund.provider_wallet_transaction_id', $providerRefundTransactionId);
            data_set($itemDetails, 'refund.raw_response', $refundResult['raw_response'] ?? '');
            data_set($itemDetails, 'refund.tickets_issued', $refundResult['tickets_issued'] ?? []);
            data_set($itemDetails, 'refund.refunded_at', now()->toIso8601String());

            $item->update([
                'status' => 'refunded',
                'item_details' => $itemDetails,
            ]);

            $booking->update([
                'status' => 'refunded',
                'amount_refunded' => round((float) $booking->amount_refunded + $walletResult['net_refund_amount'], 2),
            ]);
        });

        $this->dispatchDelayedAirlineBalanceUpdate($providerConfig);

        $netRefund = (float) data_get($item->fresh()?->item_details, 'refund.net_refund_amount', 0);
        $contact = OrderContact::fromOrder($booking);
        if (filled($contact->email) || filled($contact->phone)) {
            $contact->notify(new TicketCancelled($booking, $item, $netRefund));
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Refund processed successfully.',
                'data' => [
                    'booking_id' => $booking->id,
                    'status' => 'refunded',
                    'net_refund_amount' => $netRefund,
                    'penalty_amount' => $penaltyAmount,
                    'currency' => $booking->currency,
                ],
            ]);
        }

        return back()->with('success', 'Refund processed successfully.');
    }

    /**
     * Enrich issued order items with fare breakdown data from the formatted PNR so that
     * ApplyFinancialSourceAndCommission can resolve base fare and tax totals correctly.
     * The fare_store data is distributed across items by index (one FareStore per passenger).
     *
     * @param  array<string, mixed>  $formattedPnr
     */
    protected function enrichItemsWithFareData(Order $order, array $formattedPnr): void
    {
        $order->loadMissing('items');

        $fareStores = (array) data_get($formattedPnr, 'fare_store', []);
        $totalFare = data_get($formattedPnr, 'total_fare');
        $totalTax = data_get($formattedPnr, 'total_tax');

        foreach ($order->items->values() as $index => $item) {
            $details = (array) $item->item_details;

            // Assign the per-passenger FareStore entry if available, otherwise fall back to totals.
            if (isset($fareStores[$index])) {
                $details['fare_store'] = [$fareStores[$index]];
            } elseif ($fareStores !== []) {
                $details['fare_store'] = [$fareStores[0]];
            }

            if ($totalFare !== null) {
                $details['total_fare'] = $totalFare;
            }

            if ($totalTax !== null) {
                $details['total_tax'] = $totalTax;
            }

            $item->update(['item_details' => $details]);
        }

        $order->unsetRelation('items');
        $order->loadMissing('items');
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

    protected function resolveIssueDateForVoid(OrderItem $item, ?Order $booking = null): ?Carbon
    {
        $issueDate = (string) data_get($item->item_details, 'tickets.0.issue_date', '');
        if ($issueDate === '') {
            $issueDate = (string) data_get($item->item_details, 'payments.0.date', '');
        }

        if ($issueDate !== '') {
            try {
                return Carbon::parse($issueDate)->startOfDay();
            } catch (\Throwable) {
                // fall through to model timestamps below
            }
        }

        // Fall back to model timestamps when item_details lacks the issue date
        // (e.g. tickets booked but not formally ticketed via HX command).
        $fallback = $item->updated_at ?? $booking?->issued_at;

        return $fallback ? Carbon::instance($fallback)->startOfDay() : null;
    }

    public function resolveProviderForTicketActionPublic(OrderItem $item): ?TenantProvider
    {
        return $this->resolveProviderForTicketAction($item);
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
     * @return array{refund_transaction_id:?string,penalty_transaction_id:?string,gross_refund_amount:float,penalty_amount:float,net_refund_amount:float}
     */
    protected function depositRefundAmountToWallet(Order $booking, OrderItem $orderItem, string $ticketNumber, float $penaltyAmount, ?User $user): array
    {
        $refundTarget = $this->resolveVoidRefundTarget($orderItem, $user);
        if (! $refundTarget) {
            return [
                'refund_transaction_id' => null,
                'penalty_transaction_id' => null,
                'gross_refund_amount' => 0.0,
                'penalty_amount' => 0.0,
                'net_refund_amount' => 0.0,
            ];
        }

        $walletHolder = $refundTarget['holder'];
        $grossRefundAmount = round((float) $refundTarget['amount'], 2);
        $penaltyAmount = min(max(round($penaltyAmount, 2), 0.0), $grossRefundAmount);

        if ($grossRefundAmount <= 0) {
            return [
                'refund_transaction_id' => null,
                'penalty_transaction_id' => null,
                'gross_refund_amount' => 0.0,
                'penalty_amount' => 0.0,
                'net_refund_amount' => 0.0,
            ];
        }

        $wallet = $walletHolder->getOrCreateCurrencyWallet($booking->currency);
        $refundTransaction = $wallet->depositFloat($grossRefundAmount, [
            'order_id' => $booking->id,
            'order_item_id' => $orderItem->id,
            'type' => 'ticket_refund',
            'description' => "Refund for ticket {$ticketNumber}",
        ]);

        $penaltyTransaction = null;

        if ($penaltyAmount > 0) {
            $penaltyTransaction = $wallet->withdrawFloat($penaltyAmount, [
                'order_id' => $booking->id,
                'order_item_id' => $orderItem->id,
                'type' => 'ticket_refund_penalty',
                'description' => "Refund penalty for ticket {$ticketNumber}",
            ]);
        }

        return [
            'refund_transaction_id' => $refundTransaction?->uuid,
            'penalty_transaction_id' => $penaltyTransaction?->uuid,
            'gross_refund_amount' => $grossRefundAmount,
            'penalty_amount' => $penaltyAmount,
            'net_refund_amount' => round($grossRefundAmount - $penaltyAmount, 2),
        ];
    }

    protected function depositVoidAmountToProviderWallet(OrderItem $orderItem, string $pnr): ?string
    {
        $originalTransactionUuid = (string) (
            data_get($orderItem->item_details, 'provider_wallet_transaction_id')
            ?: $orderItem->wallet_transaction_id
        );

        if ($originalTransactionUuid === '') {
            return null;
        }

        $originalTransaction = WalletTransaction::query()
            ->where('uuid', $originalTransactionUuid)
            ->first(['wallet_id', 'amount']);

        if (! $originalTransaction?->wallet_id) {
            return null;
        }

        $walletOwner = DB::table('wallets')
            ->where('id', (int) $originalTransaction->wallet_id)
            ->first(['holder_type', 'holder_id', 'slug', 'meta']);

        if (! $walletOwner || (string) $walletOwner->holder_type !== TenantProvider::class) {
            return null;
        }

        $provider = TenantProvider::query()->find((int) $walletOwner->holder_id);

        if (! $provider instanceof TenantProvider) {
            return null;
        }

        $amount = round(abs((float) $originalTransaction->amount) / 100, 2);

        if ($amount <= 0) {
            return null;
        }

        $currency = (string) ($orderItem->currency ?: data_get(json_decode((string) $walletOwner->meta, true), 'currency', 'LYD'));
        $wallet = $provider->getOrCreateCurrencyWallet($currency);

        $transaction = $wallet->depositFloat($amount, [
            'order_id' => $orderItem->order_id,
            'order_item_id' => $orderItem->id,
            'type' => 'provider_void_refund',
            'provider_type' => 'airline',
            'provider_id' => $provider->id,
            'airline_code' => $provider->airline_code,
            'provider_reference' => $pnr,
            'original_transaction_id' => $originalTransactionUuid,
            'description' => "Provider wallet reversal for voided PNR {$pnr}",
        ]);

        return $transaction?->uuid;
    }

    protected function depositRefundAmountToProviderWallet(OrderItem $orderItem, string $ticketNumber): ?string
    {
        $originalTransactionUuid = (string) (
            data_get($orderItem->item_details, 'provider_wallet_transaction_id')
            ?: $orderItem->wallet_transaction_id
        );

        if ($originalTransactionUuid === '') {
            return null;
        }

        $originalTransaction = WalletTransaction::query()
            ->where('uuid', $originalTransactionUuid)
            ->first(['wallet_id', 'amount']);

        if (! $originalTransaction?->wallet_id) {
            return null;
        }

        $walletOwner = DB::table('wallets')
            ->where('id', (int) $originalTransaction->wallet_id)
            ->first(['holder_type', 'holder_id', 'slug', 'meta']);

        if (! $walletOwner || (string) $walletOwner->holder_type !== TenantProvider::class) {
            return null;
        }

        $provider = TenantProvider::query()->find((int) $walletOwner->holder_id);

        if (! $provider instanceof TenantProvider) {
            return null;
        }

        $amount = round(abs((float) $originalTransaction->amount) / 100, 2);

        if ($amount <= 0) {
            return null;
        }

        $currency = (string) ($orderItem->currency ?: data_get(json_decode((string) $walletOwner->meta, true), 'currency', 'LYD'));
        $wallet = $provider->getOrCreateCurrencyWallet($currency);

        $transaction = $wallet->depositFloat($amount, [
            'order_id' => $orderItem->order_id,
            'order_item_id' => $orderItem->id,
            'type' => 'provider_ticket_refund',
            'provider_type' => 'airline',
            'provider_id' => $provider->id,
            'airline_code' => $provider->airline_code,
            'ticket_number' => $ticketNumber,
            'original_transaction_id' => $originalTransactionUuid,
            'description' => "Provider wallet reversal for refunded ticket {$ticketNumber}",
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

    protected function postVoidLedgerReversal(Order $booking, OrderItem $orderItem): void
    {
        $sellingPrice = round((float) $orderItem->total_amount ?: (float) $orderItem->total, 3);
        $taxTotal = round((float) $orderItem->total_tax, 3);
        $commissionAmount = round((float) $orderItem->commission_amount, 3);
        // True provider cost mirrors the issue entry: base fare net of commission + taxes
        $baseFare = round((float) $orderItem->net_fare, 3);
        $providerCost = round($baseFare - $commissionAmount + $taxTotal, 3);
        $productType = match ((string) $orderItem->product_type) {
            'flight', 'ticket' => 'airline',
            default => (string) $orderItem->product_type,
        };

        try {
            $this->ledgerPostingService->postReversalEntry(
                originalOrderId: (string) $booking->id,
                sellingPrice: $sellingPrice,
                productType: $productType,
                taxTotal: $taxTotal,
                commissionAmount: $commissionAmount,
                providerCost: $providerCost,
                cancellationFee: null,
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
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
    protected function assertWalletBalanceBeforeIssue(Order $booking, User $issuer, TenantProvider $providerConfig): void
    {
        $booking->loadMissing('items');

        $requiredByCurrency = [];
        $financialSourceAction = app(DetermineFinancialSource::class);

        foreach ($booking->items as $item) {
            $airlineCode = (string) data_get($item->item_details, 'airline_code', data_get($item->product_details, 'airline_code', ''));
            $currency = strtoupper((string) $item->currency);
            $source = $financialSourceAction->execute($airlineCode, $currency);
            $requiredAmount = round((float) ($item->total_amount ?? $item->total ?? 0), 2);

            if ($source->usesOwnCredentials()) {
                app(ProcessProviderWalletTransactions::class)->assertCanWithdraw($providerConfig, $currency, $requiredAmount);

                continue;
            }

            if (! $source->usesMasterAgencySupply()) {
                continue;
            }

            $requiredByCurrency[$currency] = round(
                ((float) ($requiredByCurrency[$currency] ?? 0)) + $requiredAmount,
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
                'commission_amount' => $commissionAllocations[$index] ?? 0.0,
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

    /**
     * Get a change quote for a ticket item — returns outstanding amount without committing.
     *
     * Expects query params:
     *   - segment_line:      int    — the itinerary line number to replace (1-based)
     *   - new_segment_code:  string — the new Videcom segment code (e.g. 0YL0800Y24MarMJITUNNN1)
     */
    public function changeQuote(Request $request, Order $booking, string $ticket): \Illuminate\Http\JsonResponse
    {
        $item = $booking->items()->whereKey($ticket)->first();
        if (! $item) {
            return response()->json(['error' => 'Ticket item not found.'], 404);
        }

        $segmentLine = (int) $request->input('segment_line', 1);
        $newSegmentCode = trim((string) $request->input('new_segment_code', ''));

        if ($segmentLine < 1) {
            return response()->json(['error' => 'segment_line must be a positive integer.'], 422);
        }

        if ($newSegmentCode === '') {
            return response()->json(['error' => 'new_segment_code is required.'], 422);
        }

        $pnr = (string) ($item->provider_reference ?: $booking->payment_reference);
        if ($pnr === '') {
            return response()->json(['error' => 'PNR reference not found for this order item.'], 422);
        }

        $providerConfig = $this->resolveProviderForTicketAction($item);
        if (! $providerConfig) {
            return response()->json(['error' => 'No active provider found for this booking.'], 422);
        }

        $provider = ProviderFactory::make($providerConfig);

        try {
            $quote = $provider->changeQuote($pnr, $segmentLine, $newSegmentCode);
        } catch (ConnectionException $exception) {
            report($exception);

            return response()->json(['error' => 'Airline change quote request timed out. Please try again.'], 503);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to fetch change quote from airline provider.'], 500);
        }

        // Determine change type by comparing new segment against the original stored segment.
        $originalSegments = (array) data_get($item->item_details, 'segments', []);
        $originalSegment = collect($originalSegments)->firstWhere('line', $segmentLine)
            ?? ($originalSegments[$segmentLine - 1] ?? null);

        $changeType = $this->determineChangeType($newSegmentCode, $originalSegment);

        return response()->json(array_merge($quote, ['change_type' => $changeType]));
    }

    /**
     * Render the change review page (receives offer data POSTed from ChangeOffers).
     */
    public function changeReview(Request $request, Order $booking, string $ticket): InertiaResponse|RedirectResponse
    {
        $item = $booking->items()->whereKey($ticket)->first();
        if (! $item) {
            return back()->with('error', 'Ticket item not found.');
        }

        $segmentLine = (int) $request->input('segment_line', 1);
        $newSegmentCode = trim((string) $request->input('new_segment_code', ''));
        $reservationType = trim((string) $request->input('reservation_type', 'NN'));
        $flight = $request->input('flight', []);

        if ($segmentLine < 1 || $newSegmentCode === '') {
            return back()->with('error', 'segment_line and new_segment_code are required.');
        }

        // Fetch a change quote to show the penalty amount.
        $providerConfig = $this->resolveProviderForTicketAction($item);
        $penaltyAmount = null;
        $currency = $booking->currency;

        if ($providerConfig) {
            try {
                $pnr = (string) ($item->provider_reference ?: $booking->payment_reference);
                $provider = ProviderFactory::make($providerConfig);
                $quote = $provider->changeQuote($pnr, $segmentLine, $newSegmentCode);
                $penaltyAmount = $quote['outstanding_amount'] ?? null;
                $currency = $quote['currency'] ?? $currency;
            } catch (\Throwable) {
                // Non-fatal — show review page without penalty amount.
            }
        }

        $originalSegments = (array) data_get($item->item_details, 'itineraries', data_get($item->item_details, 'segments', []));
        $originalSegment = collect($originalSegments)->firstWhere('line', $segmentLine)
            ?? ($originalSegments[$segmentLine - 1] ?? null);

        $passengers = (array) data_get($item->item_details, 'passengers', []);

        return Inertia::render('Tenant/Bookings/ChangeReview', [
            'order' => $booking,
            'item' => $item,
            'segment_line' => $segmentLine,
            'new_segment_code' => $newSegmentCode,
            'reservation_type' => $reservationType,
            'flight' => $flight,
            'original_segment' => $originalSegment,
            'passengers' => $passengers,
            'penalty_amount' => $penaltyAmount,
            'currency' => $currency,
        ]);
    }

    /**
     * Render the change confirmation page after a successful ticket change.
     */
    public function changeConfirmation(Request $request, Order $booking, string $ticket): InertiaResponse|RedirectResponse
    {
        $item = $booking->items()->whereKey($ticket)->first();
        if (! $item) {
            return redirect()->route('orders.index')->with('error', 'Ticket item not found.');
        }

        $changeDetails = (array) data_get($item->item_details, 'change', []);

        return Inertia::render('Tenant/Bookings/ChangeConfirmation', [
            'order' => $booking,
            'item' => $item,
            'change' => $changeDetails,
        ]);
    }

    /**
     * Confirm a ticket change (revalidation or reissue).
     *
     * Expects JSON body:
     *   - segment_line:      int    — the itinerary line number to replace (1-based)
     *   - new_segment_code:  string — the new Videcom segment code
     *   - change_type:       string — 'revalidation' or 'reissue' (validated server-side too)
     */
    public function confirmChange(Request $request, Order $booking, string $ticket): RedirectResponse
    {
        $item = $booking->items()->whereKey($ticket)->first();
        if (! $item) {
            return back()->with('error', 'Ticket item not found.');
        }

        $segmentLine = (int) $request->input('segment_line', 1);
        $newSegmentCode = trim((string) $request->input('new_segment_code', ''));
        $outstandingAmount = (float) $request->input('outstanding_amount', 0.0);

        if ($segmentLine < 1 || $newSegmentCode === '') {
            return back()->with('error', 'segment_line and new_segment_code are required.');
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

        // Re-derive change type server-side — never trust the client value alone.
        // item_details stores segments under 'itineraries'; fall back to 'segments' for legacy records.
        $originalSegments = (array) data_get($item->item_details, 'itineraries',
            data_get($item->item_details, 'segments', [])
        );
        $originalSegment = collect($originalSegments)->firstWhere('line', $segmentLine)
            ?? collect($originalSegments)->firstWhere('itinerary_id', $segmentLine)
            ?? ($originalSegments[$segmentLine - 1] ?? null);

        $changeType = $this->determineChangeType($newSegmentCode, $originalSegment);

        try {
            $result = $provider->confirmChange($pnr, $segmentLine, $newSegmentCode, $changeType, $outstandingAmount);
        } catch (ConnectionException $exception) {
            report($exception);

            return back()->with('error', 'Airline change request timed out. Please try again.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Failed to confirm ticket change with the airline provider.');
        }

        if (! ($result['success'] ?? false)) {
            return back()->with('error', 'Airline rejected the change request. Response: '.($result['raw_response'] ?? 'unknown'));
        }

        DB::transaction(function () use ($item, $booking, $segmentLine, $newSegmentCode, $changeType, $result): void {
            $itemDetails = (array) $item->item_details;
            data_set($itemDetails, 'change.segment_line', $segmentLine);
            data_set($itemDetails, 'change.new_segment_code', $newSegmentCode);
            data_set($itemDetails, 'change.change_type', $changeType);
            data_set($itemDetails, 'change.raw_response', $result['raw_response'] ?? '');
            data_set($itemDetails, 'change.changed_at', now()->toIso8601String());

            $item->update([
                'status' => 'changed',
                'item_details' => $itemDetails,
            ]);

            $booking->update(['status' => 'changed']);
        });

        $this->dispatchDelayedAirlineBalanceUpdate($providerConfig);

        return redirect()->route('tickets.changeConfirmation', ['booking' => $booking->id, 'ticket' => $item->id])
            ->with('success', 'Ticket changed successfully.');
    }

    /**
     * Determine whether a segment change is a revalidation or reissue.
     *
     * Revalidation: same origin + same destination + same booking class.
     * Reissue:      different origin OR different destination OR different booking class.
     *
     * The new segment code format is: {seats}{airline}{flt}{class}{date}{origin}{dest}NN{qty}
     * Example: 0YL0800Y24MarMJITUNNN1
     *   - seats:   0
     *   - airline: YL
     *   - flt:     0800
     *   - class:   Y
     *   - date:    24Mar
     *   - origin:  MJI
     *   - dest:    TUN
     *   - NN:      NN
     *   - qty:     1
     *
     * @param  array<string, mixed>|null  $originalSegment
     */
    protected function determineChangeType(string $newSegmentCode, ?array $originalSegment): string
    {
        if (! $originalSegment) {
            // No original segment to compare — default to reissue (safer).
            return 'reissue';
        }

        // Parse the new segment code.
        // Format: {seats}{airline(2)}{flt(4)}{class(1)}{date(5)}{origin(3)}{dest(3)}NN{qty}
        // We need class (1 char after 7-char prefix), origin (3 chars), dest (3 chars).
        // Regex: ^\d+([A-Z]{2})(\d{4})([A-Z])(\d{2}[A-Z]{3})([A-Z]{3})([A-Z]{3})
        if (preg_match('/^\d+([A-Z]{2})(\d{4})([A-Z])(\d{2}[A-Z]{3})([A-Z]{3})([A-Z]{3})/i', $newSegmentCode, $matches) !== 1) {
            // Cannot parse — default to reissue.
            return 'reissue';
        }

        $newClass = strtoupper($matches[3]);
        $newOrigin = strtoupper($matches[5]);
        $newDest = strtoupper($matches[6]);

        $origClass = strtoupper((string) ($originalSegment['class'] ?? ''));
        $origOrigin = strtoupper((string) ($originalSegment['departure_airport'] ?? $originalSegment['from'] ?? ''));
        $origDest = strtoupper((string) ($originalSegment['arrival_airport'] ?? $originalSegment['to'] ?? ''));

        if ($newOrigin === $origOrigin && $newDest === $origDest && $newClass === $origClass) {
            return 'revalidation';
        }

        return 'reissue';
    }
}
