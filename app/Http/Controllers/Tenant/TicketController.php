<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\UpdateAirlineBalanceJob;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\Videcom\VidecomPnrParser;
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
    public function issue(Request $request, Order $booking): RedirectResponse
    {
        $booking->loadMissing('items');

        $firstItem = $booking->items->first();
        if (! $firstItem) {
            return back()->with('error', 'No flight item found for this booking.');
        }

        $pnr = (string) ($firstItem->provider_reference ?: $booking->payment_reference);
        $airlineCode = (string) data_get($firstItem->item_details, 'airline_code', '');

        $providerConfig = TenantProvider::query()
            ->where('is_active', true)
            ->when($airlineCode !== '', fn ($query) => $query->where('airline_code', $airlineCode))
            ->first();

        if (! $providerConfig) {
            return back()->with('error', 'No active provider found for this booking.');
        }

        $provider = ProviderFactory::make($providerConfig);

        try {
            $provider->issueTicket($pnr, [
                'type' => strtolower((string) $request->input('payment_type', 'airline_token')),
                'user' => $request->user(),
            ]);

            $this->dispatchDelayedAirlineBalanceUpdate($providerConfig);

            $pnrResponse = method_exists($provider, 'queryPnr')
                ? $provider->queryPnr($pnr)
                : $provider->retrieveBooking($pnr);
        } catch (ConnectionException $exception) {
            report($exception);

            return back()->with('error', 'Airline ticket issuance timed out. Please try again.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Failed to issue ticket with the airline provider.');
        }

        $pnrXml = $this->toXml($pnrResponse);
        if (! $pnrXml) {
            return back()->with('error', 'Ticket issuance completed, but provider returned invalid PNR payload.');
        }

        $parsed = VidecomPnrParser::parse($pnrXml);
        $formatted = VidecomPnrParser::formatForOrderDetails($pnrXml);
        $ticketsByNumber = collect($parsed['tickets'] ?? [])->groupBy('ticket_number');

        foreach ($booking->items as $item) {
            $ticketNumber = (string) ($item->ticket_number ?? '');
            $ticketRows = $ticketNumber !== '' ? $ticketsByNumber->get($ticketNumber) : null;

            if (! $ticketRows instanceof Collection || $ticketRows->isEmpty()) {
                $ticketRows = $ticketsByNumber->first();
            }

            $ticketData = $ticketRows?->first() ?? [];
            $resolvedTicketNumber = (string) ($ticketData['ticket_number'] ?? $ticketNumber);

            $details = $formatted;
            $details['pnr_synced_at'] = now()->toIso8601String();

            $item->update([
                'provider_reference' => (string) ($parsed['rloc'] ?? $pnr),
                'ticket_number' => $resolvedTicketNumber !== '' ? $resolvedTicketNumber : $item->ticket_number,
                'item_details' => $details,
                'status' => 'issued',
                'remaining' => 0,
                'paid' => (float) $item->total,
            ]);
        }

        $booking->update([
            'status' => 'confirmed',
            'issued_at' => now(),
            'amount_paid' => (float) $booking->grand_total,
        ]);

        return redirect()
            ->route('tickets.completed', ['booking' => $booking->id, 'order' => $booking->id])
            ->with('success', 'Ticket issued successfully.');
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

        DB::transaction(function () use ($booking, $voidedItems, $voidSnapshot, $pnr, $voidedAmount, $oldStatus, $request): void {
            foreach ($voidedItems as $orderItem) {
                $details = $voidSnapshot;
                $details['pnr_synced_at'] = now()->toIso8601String();

                $orderItem->update([
                    'provider_reference' => (string) ($voidSnapshot['rloc'] ?? $pnr),
                    'ticket_number' => null,
                    'item_details' => $details,
                    'status' => 'voided',
                    'remaining' => 0,
                ]);
            }

            $booking->update([
                'status' => 'voided',
                'amount_refunded' => (float) $booking->amount_refunded + $voidedAmount,
            ]);

            $this->depositVoidAmountToWallet($booking, $voidedAmount, $pnr, $request->user());

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

    protected function depositVoidAmountToWallet(Order $booking, float $amount, string $pnr, ?User $user): void
    {
        if ($amount <= 0) {
            return;
        }

        if (! $user) {
            return;
        }

        $walletHolder = $this->resolveAgencyWalletHolder($user);
        $wallet = $walletHolder->getOrCreateCurrencyWallet($booking->currency);

        $wallet->deposit($this->toMinor((string) $amount), [
            'order_id' => $booking->id,
            'type' => 'ticket_void_refund',
            'description' => "Void refund for PNR {$pnr}",
        ]);
    }

    protected function resolveAgencyWalletHolder(User $fallback): User
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

    protected function dispatchDelayedAirlineBalanceUpdate(TenantProvider $provider): void
    {
        UpdateAirlineBalanceJob::dispatch($provider->id)
            ->delay(now()->addMinutes(10));
    }
}
