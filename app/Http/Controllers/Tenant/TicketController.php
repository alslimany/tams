<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Orders\CreateOrderFromVidecomResponse;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Booking;
use App\Models\Tenant\Order;
use App\Models\Tenant\Ticket;
use App\Models\User;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\Videcom\VidecomPnrParser;
use App\Services\Videcom\VidecomOrderParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use SimpleXMLElement;

class TicketController extends Controller
{
    public function issue(Request $request, Booking $booking): RedirectResponse
    {
        $provider = ProviderFactory::make($booking->provider);
        $issuer = $request->user();
        $paymentType = strtolower((string) $request->input('payment_type', 'airline_token'));

        try {
            $issueResponse = $provider->issueTicket($booking->pnr, [
                'type' => $paymentType,
                'booking' => $booking,
                'booking_id' => $booking->id,
                'user' => $issuer,
                'user_id' => $issuer?->id,
            ]);
        } catch (ConnectionException $exception) {
            report($exception);

            return back()->with('error', 'Airline ticket issuance timed out. Please confirm booking again and retry issuing.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Failed to send ticket issue command to the airline. Please try again.');
        }

        $xmlIssueResponse = is_array($issueResponse)
            ? ($issueResponse['xml'] ?? $issueResponse['raw'] ?? null)
            : $issueResponse;

        $issueXml = $this->toXml($xmlIssueResponse);

        if (! $issueXml instanceof SimpleXMLElement) {
            return back()->with('error', 'Ticket issuance failed: airline response was not valid XML.');
        }

        $order = $this->resolveCreatedOrder($issueResponse);

        if (! $order && $issuer instanceof User) {
            $order = $this->createOrderFromIssueResponse($booking, $issueXml, $issuer);
        }

        if (! $order) {
            return back()->with('error', 'Ticket issuance succeeded, but order creation failed.');
        }

        try {
            $pnrResponse = $provider->retrieveBooking($booking->pnr);
        } catch (ConnectionException $exception) {
            report($exception);

            return back()->with('error', 'Ticket was issued, but retrieval timed out. Please refresh booking details and verify ticket status.');
        }

        $parsedPnrXml = $this->toXml($pnrResponse) ?? $issueXml;
        $parsedPnr = $parsedPnrXml instanceof SimpleXMLElement ? VidecomPnrParser::parse($parsedPnrXml) : null;
        $parsedTickets = collect($parsedPnr['tickets'] ?? [])
            ->filter(fn (array $ticket): bool => filled($ticket['ticket_number'] ?? null))
            ->groupBy('ticket_number');

        $ticketNumber = $parsedTickets
            ->sortBy(fn ($entries) => $entries->contains(fn (array $ticket): bool => blank($ticket['tkt_for'] ?? null)) ? 0 : 1)
            ->keys()
            ->first();

        if (! $ticketNumber) {
            $ticketNumber = $this->extractTicketNumber($xmlIssueResponse) ?? strtoupper(Str::random(10));
        }

        $booking->update([
            'status' => 'ticketed',
            'ticket_number' => $ticketNumber,
            'ticketed_at' => now(),
            'raw_response' => [
                'issue' => $this->normalizeResponse($xmlIssueResponse),
                'issued_pnr' => $parsedPnr ?? $this->normalizeResponse($pnrResponse),
                'order_id' => $order?->id,
            ],
        ]);

        if ($parsedTickets->isNotEmpty()) {
            foreach ($parsedTickets as $parsedTicketNumber => $entries) {
                $booking->tickets()->updateOrCreate(
                    ['ticket_number' => $parsedTicketNumber],
                    [
                        'status' => 'issued',
                        'issued_at' => now(),
                        'raw_response' => [
                            'issue' => $this->normalizeResponse($xmlIssueResponse),
                            'ticket_entries' => $entries->values()->all(),
                        ],
                    ]
                );
            }
        } else {
            $booking->tickets()->updateOrCreate(
                ['ticket_number' => $ticketNumber],
                [
                    'status' => 'issued',
                    'issued_at' => now(),
                    'raw_response' => $this->normalizeResponse($xmlIssueResponse),
                ]
            );
        }

        $booking->provider()->update([
            'last_used_at' => now(),
        ]);

        return redirect()
            ->route('tickets.completed', ['booking' => $booking, 'order' => $order->id])
            ->with('success', 'Ticket issued and order created successfully.');
    }

    public function completed(Request $request, Booking $booking): InertiaResponse
    {
        $booking->load(['customer', 'passengers', 'flightSegments', 'provider', 'tickets']);

        $orderId = $request->query('order');

        $order = null;
        if (is_string($orderId) && $orderId !== '') {
            $order = Order::query()
                ->with(['items'])
                ->find($orderId);
        }

        if (! $order) {
            $order = Order::query()
                ->with(['items'])
                ->whereHas('items', function ($query) use ($booking): void {
                    $query->where('provider_reference', $booking->pnr);
                })
                ->latest('issued_at')
                ->first();
        }

        return Inertia::render('Tenant/Bookings/Completed', [
            'booking' => $booking,
            'order' => $order,
        ]);
    }

    public function void(Booking $booking, Ticket $ticket): RedirectResponse
    {
        $provider = \App\Services\Airline\ProviderFactory::make($booking->provider);
        $response = $provider->void($booking->pnr, $ticket->ticket_number);

        $ticket->update([
            'status' => 'voided',
            'voided_at' => now(),
            'raw_response' => $this->normalizeResponse($response),
        ]);

        $booking->update([
            'status' => 'cancelled',
            'raw_response' => $this->normalizeResponse($response),
        ]);

        return back()->with('success', 'Ticket voided successfully.');
    }

    public function refund(Booking $booking, Ticket $ticket): RedirectResponse
    {
        $provider = \App\Services\Airline\ProviderFactory::make($booking->provider);
        $response = $provider->refund($ticket->ticket_number);

        $ticket->update([
            'status' => 'refunded',
            'refunded_at' => now(),
            'raw_response' => $this->normalizeResponse($response),
        ]);

        $booking->update([
            'status' => 'refunded',
            'refunded_at' => now(),
            'raw_response' => $this->normalizeResponse($response),
        ]);

        return back()->with('success', 'Refund recorded successfully.');
    }

    protected function extractTicketNumber(mixed $response): ?string
    {
        if ($response instanceof \SimpleXMLElement) {
            $xml = $response->asXML() ?: '';

            if (preg_match('/\b\d{10,14}\b/', $xml, $matches)) {
                return $matches[0];
            }
        }

        if (is_string($response) && preg_match('/\b\d{10,14}\b/', $response, $matches)) {
            return $matches[0];
        }

        return null;
    }

    protected function normalizeResponse(mixed $response): array
    {
        if ($response instanceof \SimpleXMLElement) {
            return [
                'xml' => $response->asXML(),
            ];
        }

        if (is_array($response)) {
            return [
                'raw' => json_encode($response),
            ];
        }

        return [
            'raw' => is_string($response) ? $response : json_encode($response),
        ];
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

    protected function resolveCreatedOrder(mixed $issueResponse): ?Order
    {
        if (! is_array($issueResponse)) {
            return null;
        }

        $orderId = $issueResponse['order_id'] ?? null;

        if (! is_string($orderId)) {
            return null;
        }

        return Order::query()->find($orderId);
    }

    protected function createOrderFromIssueResponse(Booking $booking, mixed $issueResponse, User $issuer): ?Order
    {
        $xml = $this->toXml($issueResponse);

        if (! $xml instanceof SimpleXMLElement) {
            return null;
        }

        try {
            $parsed = app(VidecomOrderParser::class)->parse($xml->asXML() ?: '');

            return app(CreateOrderFromVidecomResponse::class)->execute($parsed, $booking, $issuer);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
