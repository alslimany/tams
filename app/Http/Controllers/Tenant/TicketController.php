<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Booking;
use App\Models\Tenant\Ticket;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\Videcom\VidecomPnrParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use SimpleXMLElement;

class TicketController extends Controller
{
    public function issue(Booking $booking): RedirectResponse
    {
        $provider = ProviderFactory::make($booking->provider);
        $issueResponse = $provider->issueTicket($booking->pnr, ['type' => 'cash']);
        $pnrResponse = $provider->retrieveBooking($booking->pnr);
        $parsedPnrXml = $this->toXml($pnrResponse);
        $parsedPnr = $parsedPnrXml instanceof SimpleXMLElement ? VidecomPnrParser::parse($parsedPnrXml) : null;
        $parsedTickets = collect($parsedPnr['tickets'] ?? [])
            ->filter(fn (array $ticket): bool => filled($ticket['ticket_number'] ?? null))
            ->groupBy('ticket_number');

        $ticketNumber = $parsedTickets
            ->sortBy(fn ($entries) => $entries->contains(fn (array $ticket): bool => blank($ticket['tkt_for'] ?? null)) ? 0 : 1)
            ->keys()
            ->first();

        if (! $ticketNumber) {
            $ticketNumber = $this->extractTicketNumber($issueResponse) ?? strtoupper(Str::random(10));
        }

        $booking->update([
            'status' => 'ticketed',
            'ticket_number' => $ticketNumber,
            'ticketed_at' => now(),
            'raw_response' => [
                'issue' => $this->normalizeResponse($issueResponse),
                'issued_pnr' => $parsedPnr ?? $this->normalizeResponse($pnrResponse),
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
                            'issue' => $this->normalizeResponse($issueResponse),
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
                    'raw_response' => $this->normalizeResponse($issueResponse),
                ]
            );
        }

        $booking->provider()->update([
            'last_used_at' => now(),
        ]);

        return back()->with('success', 'Ticket issued successfully.');
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
}
