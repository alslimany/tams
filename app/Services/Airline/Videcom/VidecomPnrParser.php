<?php

namespace App\Services\Airline\Videcom;

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
}
