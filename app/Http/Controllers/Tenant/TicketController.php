<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\Videcom\VidecomPnrParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

            $details = is_array($item->item_details) ? $item->item_details : [];
            $details['pnr'] = $formatted;
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
            'status' => 'issued',
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

    public function void(Order $booking, string $ticket): RedirectResponse
    {
        $item = $booking->items()->whereKey($ticket)->first();
        if (! $item) {
            return back()->with('error', 'Ticket item not found.');
        }

        $item->update(['status' => 'voided']);
        $booking->update(['status' => 'voided']);

        return back()->with('success', 'Ticket voided successfully.');
    }

    public function refund(Order $booking, string $ticket): RedirectResponse
    {
        $item = $booking->items()->whereKey($ticket)->first();
        if (! $item) {
            return back()->with('error', 'Ticket item not found.');
        }

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
            'customer' => [
                'first_name' => (string) data_get($order->contact, 'first_name', ''),
                'last_name' => (string) data_get($order->contact, 'last_name', ''),
                'email' => (string) data_get($order->contact, 'email', ''),
            ],
            'tickets' => $tickets,
        ];
    }
}
