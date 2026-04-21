<?php

namespace App\Services\Videcom;

use App\DTOs\Videcom\OrderItemData;
use App\DTOs\Videcom\ParsedBookingData;
use App\Services\Airline\Videcom\VidecomPnrParser;
use InvalidArgumentException;
use SimpleXMLElement;

class VidecomOrderParser
{
    public function parse(string $xmlResponse): ParsedBookingData
    {
        $xml = simplexml_load_string($xmlResponse);

        if (! $xml instanceof SimpleXMLElement) {
            throw new InvalidArgumentException('Invalid XML response returned by Videcom issueTicket command.');
        }

        $parsed = VidecomPnrParser::parse($xml);

        $pnr = $parsed['rloc']
            ?: (string) ($xml->Locator ?? $xml->RecordLocator ?? $xml->PNR ?? '');

        $payment = $parsed['payments'][0] ?? [];
        $currency = (string) ($payment['currency'] ?? ($parsed['fare_stores'][0]['currency'] ?? 'USD'));
        $paymentMethod = $this->normalizePaymentMethod((string) ($payment['type'] ?? 'invoice'));
        $paymentReference = $payment['reference'] ?? null;

        $fareStoresByPax = collect($parsed['fare_stores'])->keyBy(fn (array $fareStore): string => (string) ($fareStore['pax'] ?? ''));
        $taxesByPax = collect($parsed['fare_taxes'])->groupBy(fn (array $tax): string => (string) ($tax['pax'] ?? ''));
        $namesByPax = collect($parsed['names'])->keyBy(fn (array $name): string => (string) ($name['pax_no'] ?? ''));

        $items = [];
        $ticketsByNumber = collect($parsed['tickets'])->groupBy('ticket_number');

        foreach ($ticketsByNumber as $ticketNumber => $ticketRows) {
            $first = $ticketRows->first() ?? [];
            $paxNo = (string) ($first['pax'] ?? '');

            $fareStore = $fareStoresByPax->get($paxNo, []);
            $taxes = (float) $taxesByPax->get($paxNo, collect([]))->sum('amount');
            $total = (float) ($fareStore['total'] ?? 0);
            $fare = max($total - $taxes, 0);

            if ($total <= 0) {
                $total = $fare + $taxes;
            }

            $pax = $namesByPax->get($paxNo, []);
            $passengerName = trim(sprintf('%s %s', $pax['first_name'] ?? '', $pax['surname'] ?? ''));
            if ($passengerName === '') {
                $passengerName = (string) ($first['tkt_for'] ?? 'Passenger');
            }

            $segments = $ticketRows->map(function (array $ticket): array {
                return [
                    'flight_number' => $ticket['flight_number'] ?? null,
                    'departure_airport' => $ticket['depart'] ?? null,
                    'arrival_airport' => $ticket['arrive'] ?? null,
                    'class' => $ticket['class'] ?? null,
                    'flight_date' => $ticket['flight_date'] ?? null,
                    'segment_no' => $ticket['seg_no'] ?? null,
                ];
            })->values()->all();

            $items[] = new OrderItemData(
                passengerName: $passengerName,
                segments: $segments,
                fare: $fare,
                taxes: $taxes,
                total: $total,
                ticketNumber: is_string($ticketNumber) && $ticketNumber !== '' ? $ticketNumber : null,
                commission: 0,
                airlineCode: $this->extractAirlineCode($segments),
                currency: $currency,
            );
        }

        if ($items === []) {
            $segments = collect($parsed['itinerary'])->map(function (array $segment): array {
                return [
                    'flight_number' => $segment['airline'].$segment['flight_number'],
                    'departure_airport' => $segment['departure_airport'],
                    'arrival_airport' => $segment['arrival_airport'],
                    'class' => $segment['class'],
                    'flight_date' => $segment['departure_date'],
                    'segment_no' => $segment['line'],
                ];
            })->values()->all();

            $total = (float) collect($parsed['fare_stores'])->sum('total');
            $taxes = (float) collect($parsed['fare_taxes'])->sum('amount');

            $items[] = new OrderItemData(
                passengerName: 'Passenger',
                segments: $segments,
                fare: max($total - $taxes, 0),
                taxes: $taxes,
                total: $total,
                ticketNumber: null,
                commission: 0,
                airlineCode: $this->extractAirlineCode($segments),
                currency: $currency,
            );
        }

        $grandTotal = (float) collect($items)->sum(fn (OrderItemData $item): float => $item->total);

        return new ParsedBookingData(
            pnr: $pnr,
            grandTotal: $grandTotal,
            currency: $currency,
            paymentMethod: $paymentMethod,
            paymentReference: $paymentReference,
            items: $items,
        );
    }

    protected function normalizePaymentMethod(string $rawMethod): string
    {
        $normalized = strtoupper(trim($rawMethod));

        return match ($normalized) {
            'MI', 'INVOICE' => 'invoice',
            'CA', 'CASH' => 'cash',
            'WALLET' => 'wallet',
            'AIRLINE_ACCOUNT' => 'airline_account',
            default => strtolower($normalized ?: 'invoice'),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     */
    protected function extractAirlineCode(array $segments): ?string
    {
        $flightNumber = (string) ($segments[0]['flight_number'] ?? '');

        if ($flightNumber === '') {
            return null;
        }

        if (preg_match('/^[A-Z]{2}/', strtoupper($flightNumber), $matches) === 1) {
            return $matches[0];
        }

        return null;
    }
}
