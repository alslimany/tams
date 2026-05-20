<?php

namespace App\Services\Airline\Videcom;

use Carbon\Carbon;
use SimpleXMLElement;

class VidecomPnrParser
{
    public static function parse(SimpleXMLElement $pnr): array
    {
        return [
            'rloc' => (string) ($pnr['RLOC'] ?? ''),
            'names' => self::parseNames($pnr),
            'itinerary' => self::parseItinerary($pnr),
            'mps' => self::parseMps($pnr),
            'apfax' => self::parseApfax($pnr),
            'fare_stores' => self::parseFareStores($pnr),
            'fare_taxes' => self::parseFareTaxes($pnr),
            'payments' => self::parsePayments($pnr),
            'tickets' => self::parseTickets($pnr),
        ];
    }

    public static function formatForOrderDetails(SimpleXMLElement $pnr): array
    {
        $parsed = self::parse($pnr);

        $itineraries = collect(self::childElements($pnr->Itinerary, 'Itin'))
            ->map(fn (SimpleXMLElement $segment): array => [
                'itinerary_id' => (string) ($segment['Line'] ?? ''),
                'is_international' => ((string) ($segment['international'] ?? '0')) === '1',
                'airline_id' => (string) ($segment['AirID'] ?? ''),
                'flight_number' => (string) ($segment['FltNo'] ?? ''),
                'class' => (string) ($segment['Class'] ?? ''),
                'cabin' => (string) ($segment['Cabin'] ?? ''),
                'class_band' => (string) ($segment['ClassBand'] ?? ''),
                'class_band_display_name' => (string) ($segment['ClassBandDisplayName'] ?? ''),
                'date' => self::normalizeDate((string) ($segment['DepDate'] ?? '')),
                'from' => (string) ($segment['Depart'] ?? ''),
                'to' => (string) ($segment['Arrive'] ?? ''),
                'departure' => (string) ($segment['DepTime'] ?? ''),
                'arrival' => (string) ($segment['ArrTime'] ?? ''),
                'status' => (string) ($segment['Status'] ?? ''),
                'number_of_passengers' => (string) ($segment['PaxQty'] ?? ''),
                'number_of_stops' => (int) ($segment['Stops'] ?? 0),
                'select_seat' => self::toBool((string) ($segment['SelectSeat'] ?? 'False')),
                'mmb_select_seat' => self::toBool((string) ($segment['MMBSelectSeat'] ?? 'False')),
                'open_seating' => self::toBool((string) ($segment['OpenSeating'] ?? 'False')),
                'mmb_checkin_allow' => self::toBool((string) ($segment['MMBCheckinAllowed'] ?? 'False')),
            ])
            ->values()
            ->all();

        $passengers = collect(self::childElements($pnr->Names, 'PAX'))
            ->map(fn (SimpleXMLElement $passenger): array => [
                'id' => (string) ($passenger['PaxNo'] ?? ''),
                'group_number' => (string) ($passenger['GrpNo'] ?? ''),
                'passenger_group_number' => (string) ($passenger['GrpPaxNo'] ?? ''),
                'title' => (string) ($passenger['Title'] ?? ''),
                'first_name' => (string) ($passenger['FirstName'] ?? ''),
                'last_name' => (string) ($passenger['Surname'] ?? ''),
                'type' => (string) ($passenger['PaxType'] ?? ''),
                'age' => (string) ($passenger['Age'] ?? ''),
            ])
            ->values()
            ->all();

        $contacts = collect(self::childElements($pnr->Contacts, 'CTC'))
            ->map(fn (SimpleXMLElement $contact): array => [
                'line' => (int) ($contact['Line'] ?? 0),
                'type' => (string) ($contact['CTCID'] ?? ''),
                'pax_id' => max(((int) ($contact['Pax'] ?? 0)) - 1, 0),
                'value' => trim((string) $contact),
            ])
            ->values()
            ->all();

        $payments = collect(self::childElements($pnr->Payments, 'FOP'))
            ->map(fn (SimpleXMLElement $payment): array => [
                'itinerary_id' => (string) ($payment['Line'] ?? ''),
                'form_of_payment_id' => (string) ($payment['FOPID'] ?? ''),
                'currency' => (string) ($payment['PayCur'] ?? ''),
                'amount' => (float) ($payment['PayAmt'] ?? 0),
                'reference' => (string) ($payment['PayRef'] ?? ''),
                'pnr_currency' => (string) ($payment['PNRCur'] ?? ''),
                'pnr_amount' => (float) ($payment['PNRAmt'] ?? 0),
                'pnr_extchange_rate' => (float) ($payment['PNRExRate'] ?? 0),
                'date' => self::normalizeDate((string) ($payment['PayDate'] ?? '')),
            ])
            ->values()
            ->all();

        $timelimits = collect(self::childElements($pnr->TimeLimits, 'TL'))
            ->map(fn (SimpleXMLElement $limit): array => [
                'id' => (string) ($limit['TLID'] ?? ''),
                'value' => trim((string) $limit),
            ])
            ->values()
            ->all();

        $tickets = collect(self::childElements($pnr->Tickets, 'TKT'))
            ->map(fn (SimpleXMLElement $ticket): array => [
                'passenger_id' => (string) ($ticket['Pax'] ?? ''),
                'ticket_id' => (string) ($ticket['TKTID'] ?? ''),
                'ticket_number' => trim((string) ($ticket['TktNo'] ?? '')),
                'coupon' => (string) ($ticket['Coupon'] ?? ''),
                'flight_date' => self::normalizeDate((string) ($ticket['TktFltDate'] ?? '')),
                'flight_number' => (string) ($ticket['TktFltNo'] ?? ''),
                'from' => (string) ($ticket['TktDepart'] ?? ''),
                'to' => (string) ($ticket['TktArrive'] ?? ''),
                'class' => (string) ($ticket['TktBClass'] ?? ''),
                'issue_date' => self::normalizeDate((string) ($ticket['IssueDate'] ?? '')),
                'status' => (string) ($ticket['Status'] ?? ''),
                'segment_number' => (string) ($ticket['SegNo'] ?? ''),
                'title' => (string) ($ticket['Title'] ?? ''),
                'first_name' => (string) ($ticket['Firstname'] ?? ''),
                'last_name' => (string) ($ticket['Surname'] ?? ''),
                'hold_pices' => (string) ($ticket['HoldPcs'] ?? ''),
                'hold_weight' => (string) ($ticket['HoldWt'] ?? ''),
                'hand_weight' => (string) ($ticket['HandWt'] ?? ''),
                'web_checkout' => self::toBool((string) ($ticket['WebCheckOut'] ?? 'False')),
            ])
            ->values()
            ->all();

        $basket = collect([
            ['id' => 'outstanding', 'node' => $pnr->Basket?->Outstanding],
            ['id' => 'outstanding_airmiles', 'node' => $pnr->Basket?->Outstandingairmiles],
        ])->filter(fn (array $item): bool => $item['node'] instanceof SimpleXMLElement)
            ->map(fn (array $item): array => [
                'id' => $item['id'],
                'currency' => (string) ($item['node']['cur'] ?? ''),
                'info' => (string) ($item['node']['info'] ?? ''),
                'amount' => (string) ($item['node']['amount'] ?? ''),
            ])
            ->values()
            ->all();

        $fareQuote = collect(self::childElements($pnr->FareQuote, 'FQItin'))
            ->map(fn (SimpleXMLElement $quote): array => [
                'segment_id' => (string) ($quote['Seg'] ?? ''),
                'basic_fare' => (string) ($quote['FQI'] ?? ''),
                'currency' => (string) ($quote['Cur'] ?? ''),
                'fare' => (string) ($quote['Fare'] ?? ''),
                'tax' => (float) (($quote['Tax1'] ?? 0) + ($quote['Tax2'] ?? 0) + ($quote['Tax3'] ?? 0)),
                'total' => (float) ($quote['Total'] ?? 0),
            ])
            ->values()
            ->all();

        $fareStore = collect(self::childElements($pnr->FareQuote, 'FareStore'))
            ->filter(fn (SimpleXMLElement $store): bool => (string) ($store['Pax'] ?? '') !== '')
            ->map(function (SimpleXMLElement $store): array {
                $segments = collect(self::childElements($store, 'SegmentFS'))
                    ->map(fn (SimpleXMLElement $segment): array => [
                        'segment_id' => (string) ($segment['Seg'] ?? ''),
                        'fare' => (float) ($segment['Fare'] ?? 0),
                        'tax1' => (float) ($segment['Tax1'] ?? 0),
                        'tax2' => (float) ($segment['Tax2'] ?? 0),
                        'tax3' => (float) ($segment['Tax3'] ?? 0),
                    ])
                    ->values();

                return [
                    'pax_id' => (string) ($store['Pax'] ?? ''),
                    'currency' => (string) ($store['Cur'] ?? ''),
                    'fare' => (float) $segments->sum('fare'),
                    'tax' => (float) $segments->sum(fn (array $segment): float => $segment['tax1'] + $segment['tax2'] + $segment['tax3']),
                    'total' => (float) ($store['Total'] ?? 0),
                    'segments' => $segments->all(),
                ];
            })
            ->values()
            ->all();

        $taxes = collect(self::childElements($pnr->FareQuote?->FareTax, 'PaxTax'))
            ->map(fn (SimpleXMLElement $tax): array => [
                'segment_id' => (string) ($tax['Seg'] ?? ''),
                'pax_id' => (string) ($tax['Pax'] ?? ''),
                'code' => (string) ($tax['Code'] ?? ''),
                'currency' => (string) ($tax['Cur'] ?? ''),
                'amount' => (string) ($tax['Amnt'] ?? ''),
                'description' => (string) ($tax['desc'] ?? ''),
            ])
            ->values()
            ->all();

        $remarks = collect(self::childElements($pnr->Remarks, 'RMK'))
            ->map(fn (SimpleXMLElement $remark): array => [
                'line' => (int) ($remark['Line'] ?? 0),
                'type' => (string) ($remark['RMKID'] ?? ''),
                'value' => trim((string) $remark),
            ])
            ->values()
            ->all();

        $totalFare = collect($fareStore)->sum('fare');
        $totalTax = collect($fareStore)->sum('tax');
        $totalPrice = collect($fareStore)->sum('total');

        if ($totalFare === 0.0 && $fareQuote !== []) {
            $totalFare = collect($fareQuote)->sum(fn (array $quote): float => (float) $quote['fare']);
        }

        if ($totalTax === 0.0 && $fareQuote !== []) {
            $totalTax = collect($fareQuote)->sum(fn (array $quote): float => (float) $quote['tax']);
        }

        if ($totalPrice === 0.0 && $fareQuote !== []) {
            $totalPrice = collect($fareQuote)->sum(fn (array $quote): float => (float) $quote['total']);
        }

        if ($totalPrice === 0.0 && $payments !== []) {
            $totalPrice = collect($payments)->sum(fn (array $payment): float => (float) $payment['amount']);
        }

        $currency = (string) ($fareStore[0]['currency'] ?? $fareQuote[0]['currency'] ?? $payments[0]['currency'] ?? '');
        $iata = (string) ($itineraries[0]['airline_id'] ?? $pnr->RLE['AirID'] ?? '');

        return [
            'itineraries' => $itineraries,
            'passengers' => $passengers,
            'contacts' => $contacts,
            'payments' => $payments,
            'timelimits' => $timelimits,
            'tickets' => $tickets,
            'remarks' => $remarks,
            'basket' => $basket,
            'mps' => $parsed['mps'],
            'fare_qoute' => $fareQuote,
            'fare_store' => $fareStore,
            'taxes' => $taxes,
            'total_fare' => self::formatDecimal($totalFare),
            'total_tax' => self::formatDecimal($totalTax),
            'total_price' => self::formatDecimal($totalPrice),
            'currency' => $currency,
            'is_issued' => collect($tickets)->isNotEmpty(),
            'is_locked' => self::toBool((string) ($pnr['PNRLocked'] ?? 'False')),
            'is_voidable' => self::toBool((string) ($pnr['CanVoid'] ?? 'False')),
            'void_cutoff_time' => self::normalizeDateTime((string) ($pnr['VoidCutoffTime'] ?? '')),
            'rloc' => (string) ($pnr['RLOC'] ?? ''),
            'iata' => $iata,
        ];
    }

    protected static function parseNames(SimpleXMLElement $pnr): array
    {
        return collect(self::childElements($pnr->Names, 'PAX'))
            ->map(fn (SimpleXMLElement $passenger): array => [
                'pax_no' => (int) ($passenger['PaxNo'] ?? 0),
                'title' => (string) ($passenger['Title'] ?? ''),
                'first_name' => (string) ($passenger['FirstName'] ?? ''),
                'surname' => (string) ($passenger['Surname'] ?? ''),
                'pax_type' => (string) ($passenger['PaxType'] ?? ''),
            ])
            ->values()
            ->all();
    }

    protected static function parseItinerary(SimpleXMLElement $pnr): array
    {
        return collect(self::childElements($pnr->Itinerary, 'Itin'))
            ->map(fn (SimpleXMLElement $segment): array => [
                'line' => (int) ($segment['Line'] ?? 0),
                'airline' => (string) ($segment['AirID'] ?? ''),
                'flight_number' => (string) ($segment['FltNo'] ?? ''),
                'class' => (string) ($segment['Class'] ?? ''),
                'departure_date' => (string) ($segment['DepDate'] ?? ''),
                'departure_airport' => (string) ($segment['Depart'] ?? ''),
                'arrival_airport' => (string) ($segment['Arrive'] ?? ''),
                'status' => (string) ($segment['Status'] ?? ''),
                'class_band' => (string) ($segment['ClassBandDisplayName'] ?? $segment['ClassBand'] ?? ''),
                'select_seat' => ((string) ($segment['SelectSeat'] ?? 'False')) === 'True',
            ])
            ->values()
            ->all();
    }

    protected static function parseMps(SimpleXMLElement $pnr): array
    {
        return collect(self::childElements($pnr->MPS, 'MP'))
            ->map(fn (SimpleXMLElement $product): array => [
                'line' => (int) ($product['Line'] ?? 0),
                'code' => (string) ($product['MPID'] ?? ''),
                'pax' => (int) ($product['Pax'] ?? 0),
                'seg' => (int) ($product['Seg'] ?? 0),
                'currency' => (string) ($product['MPSCur'] ?? ''),
                'amount' => (float) ($product['MPSAmt'] ?? 0),
                'mps_id' => (string) ($product['MPSID'] ?? ''),
                'description' => trim((string) $product),
            ])
            ->values()
            ->all();
    }

    protected static function parseApfax(SimpleXMLElement $pnr): array
    {
        return collect(self::childElements($pnr->APFAX, 'AFX'))
            ->map(fn (SimpleXMLElement $entry): array => [
                'line' => (int) ($entry['Line'] ?? 0),
                'type' => (string) ($entry['AFXID'] ?? ''),
                'pax' => (int) ($entry['Pax'] ?? 0),
                'seg' => (string) ($entry['Seg'] ?? ''),
                'seat' => (string) ($entry['seat'] ?? ''),
                'value' => trim((string) $entry),
            ])
            ->values()
            ->all();
    }

    protected static function parseFareStores(SimpleXMLElement $pnr): array
    {
        return collect(self::childElements($pnr->FareQuote, 'FareStore'))
            ->map(fn (SimpleXMLElement $store): array => [
                'fsid' => (string) ($store['FSID'] ?? ''),
                'mps_id' => (string) ($store['MPSID'] ?? ''),
                'pax' => (string) ($store['Pax'] ?? ''),
                'currency' => (string) ($store['Cur'] ?? ''),
                'total' => (float) ($store['Total'] ?? 0),
            ])
            ->values()
            ->all();
    }

    protected static function parseFareTaxes(SimpleXMLElement $pnr): array
    {
        return collect(self::childElements($pnr->FareQuote?->FareTax, 'PaxTax'))
            ->map(fn (SimpleXMLElement $tax): array => [
                'seg' => (int) ($tax['Seg'] ?? 0),
                'pax' => (int) ($tax['Pax'] ?? 0),
                'code' => (string) ($tax['Code'] ?? ''),
                'currency' => (string) ($tax['Cur'] ?? ''),
                'amount' => (float) ($tax['Amnt'] ?? 0),
                'description' => (string) ($tax['desc'] ?? ''),
            ])
            ->values()
            ->all();
    }

    protected static function parsePayments(SimpleXMLElement $pnr): array
    {
        return collect(self::childElements($pnr->Payments, 'FOP'))
            ->map(fn (SimpleXMLElement $payment): array => [
                'line' => (int) ($payment['Line'] ?? 0),
                'type' => (string) ($payment['FOPID'] ?? ''),
                'currency' => (string) ($payment['PayCur'] ?? ''),
                'amount' => (float) ($payment['PayAmt'] ?? 0),
                'reference' => (string) ($payment['PayRef'] ?? ''),
                'payment_date' => (string) ($payment['PayDate'] ?? ''),
            ])
            ->values()
            ->all();
    }

    protected static function parseTickets(SimpleXMLElement $pnr): array
    {
        return collect(self::childElements($pnr->Tickets, 'TKT'))
            ->map(fn (SimpleXMLElement $ticket): array => [
                'pax' => (int) ($ticket['Pax'] ?? 0),
                'ticket_id' => (string) ($ticket['TKTID'] ?? ''),
                'ticket_number' => trim((string) ($ticket['TktNo'] ?? '')),
                'coupon' => (string) ($ticket['Coupon'] ?? ''),
                'flight_date' => (string) ($ticket['TktFltDate'] ?? ''),
                'flight_number' => (string) ($ticket['TktFltNo'] ?? ''),
                'depart' => (string) ($ticket['TktDepart'] ?? ''),
                'arrive' => (string) ($ticket['TktArrive'] ?? ''),
                'class' => (string) ($ticket['TktBClass'] ?? ''),
                'issue_date' => (string) ($ticket['IssueDate'] ?? ''),
                'status' => (string) ($ticket['Status'] ?? ''),
                'seg_no' => (string) ($ticket['SegNo'] ?? ''),
                'tkt_for' => (string) ($ticket['TktFor'] ?? ''),
                'hold_pcs' => (string) ($ticket['HoldPcs'] ?? ''),
                'hold_wt' => (string) ($ticket['HoldWt'] ?? ''),
                'hand_wt' => (string) ($ticket['HandWt'] ?? ''),
            ])
            ->values()
            ->all();
    }

    protected static function childElements(?SimpleXMLElement $parent, string $childName): array
    {
        if (! $parent instanceof SimpleXMLElement || ! isset($parent->{$childName})) {
            return [];
        }

        $elements = [];

        foreach ($parent->{$childName} as $element) {
            if ($element instanceof SimpleXMLElement) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    protected static function toBool(string $value): bool
    {
        return strtoupper(trim($value)) === 'TRUE';
    }

    protected static function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{2})([A-Z]{3})(\d{2})$/', strtoupper($value), $matches) === 1) {
            $value = sprintf('%s%s20%s', $matches[1], $matches[2], $matches[3]);
        }

        foreach (['Y-m-d', 'dMY', 'dMy'] as $format) {
            try {
                return Carbon::createFromFormat($format, strtoupper($value))->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return $value;
        }
    }

    protected static function normalizeDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * Parse the XML response from a refund quote command (*{PNR}^FCR1^FCC1^X1^FSM^*R~X).
     *
     * Returns:
     *  - mps_penalties: array of MPS penalty lines from the airline
     *  - refund_amount:  net amount that will be credited back to our wallet (absolute value)
     *  - penalty_amount: total penalty charged by the airline (sum of MPS amounts)
     *  - currency: currency code
     *
     * @return array{mps_penalties: array<int, array<string, mixed>>, refund_amount: float, penalty_amount: float, currency: string}
     */
    public static function parseRefundQuote(SimpleXMLElement $pnr): array
    {
        $mps = self::parseMps($pnr);

        $currency = '';
        if (! empty($mps)) {
            $currency = (string) ($mps[0]['currency'] ?? '');
        }

        // Outstanding is the net refund amount — always comes as negative, we flip it.
        $outstandingNode = $pnr->Basket?->Outstanding;
        $rawOutstanding = $outstandingNode instanceof SimpleXMLElement
            ? (float) ($outstandingNode['amount'] ?? 0)
            : 0.0;

        $refundAmount = $rawOutstanding < 0 ? abs($rawOutstanding) : $rawOutstanding;

        if ($currency === '' && $outstandingNode instanceof SimpleXMLElement) {
            $currency = (string) ($outstandingNode['cur'] ?? '');
        }

        $penaltyAmount = round(array_sum(array_column($mps, 'amount')), 2);

        return [
            'mps_penalties' => $mps,
            'refund_amount' => round($refundAmount, 2),
            'penalty_amount' => $penaltyAmount,
            'currency' => $currency,
        ];
    }

    /**
     * Parse the plain-text response returned after executing a refund command.
     *
     * The response is a single long string like:
     *   "1.1MAHMOUH/FATHIMR NO ITINERARY 01 MP 1 - 1RFRC LYD 100.00 ... TICKET ISSUED 532 2000191678/01 ..."
     *
     * We extract any "TICKET ISSUED {number}" occurrences and determine success by
     * the absence of error keywords.
     *
     * @return array{success: bool, raw_response: string, tickets_issued: array<int, string>}
     */
    public static function parseRefundExecuteResponse(string $rawResponse): array
    {
        $text = trim($rawResponse);

        $errorKeywords = ['ERROR', 'NOT AUTHORISED', 'UNABLE', 'INVALID', 'FAILED'];
        $hasError = false;
        foreach ($errorKeywords as $keyword) {
            if (str_contains(strtoupper($text), $keyword)) {
                $hasError = true;
                break;
            }
        }

        // Extract all "TICKET ISSUED {number}" occurrences.
        $ticketsIssued = [];
        if (preg_match_all('/TICKET ISSUED\s+([\d\s\/]+)/i', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $ticketsIssued[] = trim($match);
            }
        }

        // A refund is successful when there are no error keywords and either
        // the response contains "TICKET ISSUED" (penalty EMDs) or the PNR rloc appears.
        $success = ! $hasError && ($text !== '');

        return [
            'success' => $success,
            'raw_response' => $text,
            'tickets_issued' => $ticketsIssued,
        ];
    }

    /**
     * Parse the XML response from a change quote command.
     *
     * Command: *{RLOC}^X{segLine}^{newSegmentCode}^FG^FS1^MB^*R~X
     *
     * Reads Basket.Outstanding to determine the amount the customer owes for the change.
     * A positive outstanding means the customer must pay the difference.
     * Zero means the change is free (same fare bucket).
     *
     * @return array{outstanding_amount: float, currency: string, raw_response: string}
     */
    public static function parseChangeQuote(SimpleXMLElement $pnr): array
    {
        $outstandingNode = $pnr->Basket?->Outstanding;

        $rawOutstanding = $outstandingNode instanceof SimpleXMLElement
            ? (float) ($outstandingNode['amount'] ?? 0)
            : 0.0;

        // Outstanding is positive when the customer owes money (fare difference).
        // It can be negative when the new fare is cheaper (credit back).
        $outstandingAmount = round($rawOutstanding, 2);

        $currency = '';
        if ($outstandingNode instanceof SimpleXMLElement) {
            $currency = (string) ($outstandingNode['cur'] ?? '');
        }

        // Fall back to fare store currency if basket currency is missing.
        if ($currency === '') {
            $fareStores = self::parseFareStores($pnr);
            $currency = (string) ($fareStores[0]['currency'] ?? '');
        }

        return [
            'outstanding_amount' => $outstandingAmount,
            'currency' => $currency,
            'raw_response' => $pnr->asXML() ?: '',
        ];
    }

    /**
     * Parse the response from a confirmChange command (revalidation or reissue).
     *
     * The response is XML for revalidation and may be plain text or XML for reissue.
     * We detect errors by checking for known error keywords in the raw string.
     *
     * @return array{success: bool, raw_response: string}
     */
    public static function parseChangeConfirmResponse(string $rawResponse): array
    {
        $text = trim($rawResponse);

        $errorKeywords = ['ERROR', 'NOT AUTHORISED', 'UNABLE', 'INVALID', 'FAILED', 'REJECTED'];
        $hasError = false;
        foreach ($errorKeywords as $keyword) {
            if (str_contains(strtoupper($text), $keyword)) {
                $hasError = true;
                break;
            }
        }

        return [
            'success' => ! $hasError && $text !== '',
            'raw_response' => $text,
        ];
    }

    protected static function formatDecimal(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');

        return str_contains($formatted, '.00')
            ? (string) ((float) $formatted)
            : rtrim(rtrim($formatted, '0'), '.');
    }
}
