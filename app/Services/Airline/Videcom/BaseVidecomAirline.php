<?php

namespace App\Services\Airline\Videcom;

use App\Services\Airline\AirlineProviderInterface;
use Exception;
use SimpleXMLElement;

abstract class BaseVidecomAirline implements AirlineProviderInterface
{
    protected VidecomClient $client;

    protected array $config;

    /**
     * BaseVidecomAirline constructor.
     *
     * @param  array  $config  Airline credentials and configuration
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = new VidecomClient($config);
    }

    /**
     * Test the connection to the provider.
     */
    public function testConnection(): bool
    {
        // Run a simple display command to verify session/token
        $this->client->runCommand('*R');

        return true;
    }

    /**
     * Get the airline IATA code.
     */
    abstract public function getIataCode(): string;

    /**
     * Get the airline name.
     */
    abstract public function getName(): string;

    /**
     * Get the airline's Videcom tenant identifier (used in URLs).
     */
    abstract public function getVidecomCode(): string;

    /**
     * Get available airports for this account.
     */
    public function getAvailableAirports(): array
    {
        return $this->config['airports'] ?? [];
    }

    /**
     * Get the account currency.
     */
    public function getCurrency(): string
    {
        return $this->config['currency'] ?? 'USD';
    }

    /**
     * Search for flight availability.
     */
    public function searchAvailability(array $params)
    {
        $rawDate = $params['date'] ?? now()->toDateTimeString();
        $date = strtoupper(\Carbon\Carbon::parse($rawDate)->format('dM'));
        $origin = strtoupper($params['origin'] ?? '');
        $destination = strtoupper($params['destination'] ?? '');
        $qty = $params['qty'] ?? 1;
        $isReturn = $params['is_return'] ?? false;

        $adults = (int) ($params['adults'] ?? 1);
        $children = (int) ($params['children'] ?? 0);
        $infants = (int) ($params['infants'] ?? 0);

        // Check if origin/dest are allowed for this account
        if (! $this->isRouteAllowed($origin, $destination)) {
            throw new Exception("Route {$origin}-{$destination} is not allowed for this airline account.");
        }

        $command = "A{$date}{$origin}{$destination}[SalesCity={$origin},VARS=True,ClassBands=True,StartCity={$origin},SingleSeg=".($isReturn ? 'r' : 's').",FGNoAv=True,qtyseats={$qty}]";

        $response = $this->client->runCommand($command);
        $xml = $this->parseXml($response);

        if (! $xml instanceof SimpleXMLElement) {
            return [];
        }

        $options = VidecomResponseParser::parseAvailability($xml, $this->getIataCode(), $this->getName());

        // Update pricing based on passenger types and cache
        foreach ($options as $option) {
            $this->applyAccuratePricing($option, $adults, $children, $infants);
        }

        return $options;
    }

    /**
     * Apply accurate pricing for multiple passenger types to a flight option.
     */
    protected function applyAccuratePricing($option, int $adults, int $children, int $infants): void
    {
        $class = $option->segments[0]['class'] ?? null;
        if (! $class) {
            return;
        }

        // Prefetch and cache all pax prices for this route/class if not already cached
        $this->prefetchPrices($option, $class);

        $total = 0;
        $breakdown = [];

        foreach (['AD' => $adults, 'CH' => $children, 'IN' => $infants] as $type => $qty) {
            if ($qty <= 0) {
                continue;
            }

            $pricing = $this->getCachedOrFallbackPrice($option, $class, $type);
            $base = (float) ($pricing['fare'] ?? 0);
            $tax = (float) ($pricing['tax'] ?? 0);
            $paxTotal = ($base + $tax) * $qty;
            $total += $paxTotal;

            $label = match ($type) {
                'AD' => 'Adult',
                'CH' => 'Child',
                'IN' => 'Infant',
            };

            $breakdown[] = [
                'label' => "{$label} (x{$qty})",
                'qty' => $qty,
                'fare' => $base,
                'tax' => $tax,
                'amount' => $paxTotal,
            ];
        }

        if ($total > 0) {
            $option->pricing['total'] = $total;
            $option->pricing['breakdown'] = $breakdown;
        }
    }

    /**
     * Ensure we have all pax prices (AD, CH, IN) in cache for this route/class.
     */
    protected function prefetchPrices($option, string $class): void
    {
        $origin = $option->departure_airport;
        $dest = $option->arrival_airport;
        $airline = $this->getIataCode();

        $missing = false;
        foreach (['AD', 'CH', 'IN'] as $type) {
            if (PricingCacheService::get($airline, $origin, $dest, $class, $type) === null) {
                $missing = true;
                break;
            }
        }

        if (! $missing) {
            return;
        }

        try {
            $prices = $this->fetchAllPaxPricesFromVrs($option, $class);
            foreach ($prices as $type => $data) {
                PricingCacheService::put($airline, $origin, $dest, $class, $type, $data);
            }
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to fetch consolidated prices for $airline $origin-$dest ($class): ".$e->getMessage());
        }
    }

    /**
     * Get a cached price or return a sensible fallback.
     */
    protected function getCachedOrFallbackPrice($option, string $class, string $paxType): array
    {
        $cached = PricingCacheService::get($this->getIataCode(), $option->departure_airport, $option->arrival_airport, $class, $paxType);

        if ($cached && is_array($cached)) {
            return $cached;
        }

        // Fallback: If Adult, use initial total. If others, assume 0.
        return [
            'fare' => $paxType === 'AD' ? ($option->pricing['total'] ?? 0) : 0,
            'tax' => 0,
        ];
    }

    /**
     * Run a pricing command to get prices for all types (AD, CH, IN) in ONE session.
     */
    protected function fetchAllPaxPricesFromVrs($option, string $class): array
    {
        // Consolidated Name Entry syntax: -[Qty][Surname]/[First1][Type]/[First2][Type]...
        // AD = Index 1, CH = Index 2, IN = Index 3
        $paxEntry = '-3PAX/A#/B#.CH10/C#.IN06';

        $date = strtoupper(\Carbon\Carbon::parse($option->departure_time)->format('dM'));

        // QQ count is 2 (AD + CH) - infants are Lap children and don't take a seat
        $flightEntry = "0{$this->getIataCode()}{$option->flight_number}{$class}{$date}{$option->departure_airport}{$option->arrival_airport}QQ2";

        $command = "i^{$paxEntry}^{$flightEntry}^FG^FS1^*r~x";
        $response = $this->client->runCommand($command);
        $xml = $this->parseXml($response);

        if (! ($xml instanceof SimpleXMLElement) || ! isset($xml->FareQuote->FareStore)) {
            throw new Exception('Invalid consolidated pricing response');
        }

        $results = [];
        $fsArray = $xml->FareQuote->FareStore;

        // Map Pax index to type
        // Pax 1 = AD, Pax 2 = CH, Pax 3 = IN
        foreach ($fsArray as $fs) {
            $paxIndex = (int) ($fs['Pax'] ?? 0);
            $type = match ($paxIndex) {
                1 => 'AD',
                2 => 'CH',
                3 => 'IN',
                default => null,
            };

            if ($type && isset($fs->SegmentFS)) {
                $results[$type] = [
                    'fare' => (float) ($fs->SegmentFS['Fare'] ?? 0),
                    'tax' => (float) ($fs->SegmentFS['Tax1'] ?? 0) + (float) ($fs->SegmentFS['Tax2'] ?? 0) + (float) ($fs->SegmentFS['Tax3'] ?? 0),
                ];
            }
        }

        return $results;
    }

    /**
     * Get pricing for a selected itinerary.
     */
    public function getPricing(array $itinerary, array $passengers)
    {
        $paxCount = count($passengers);
        $paxEntry = $this->buildPaxPricingEntry($passengers);

        $flightEntries = [];
        foreach ($itinerary as $segment) {
            $fltNo = $segment['flt_no'];
            $class = $segment['class'];
            $date = $segment['date'];
            $origin = $segment['origin'];
            $dest = $segment['dest'];
            $status = 'QQ'; // Quote Only
            $flightEntries[] = "0{$this->getIataCode()}{$fltNo}{$class}{$date}{$origin}{$dest}{$status}{$paxCount}";
        }

        $command = "i^{$paxEntry}^".implode('^', $flightEntries).'^FG^*r~x';

        $response = $this->client->runCommand($command);

        return $this->parseXml($response);
    }

    /**
     * Create a booking (PNR).
     */
    public function createBooking(array $params)
    {
        $paxInfo = $this->buildPaxInfo($params['passengers']);
        $flightSegments = $this->buildFlightSegments($params['itinerary']);

        // Default booking pattern (without explicit Cash FOP, so we just hold or default)
        $command = "{$paxInfo}^{$flightSegments}^FG^MM^*R~x";

        $response = $this->client->runCommand($command);

        return $this->parseXml($response);
    }

    /**
     * Issue tickets for a booking.
     */
    public function issueTicket(string $rloc, array $paymentInfo)
    {
        $command = "*{$rloc}^MM^EZT*R^EZRE^*R~x";
        $response = $this->client->runCommand($command);

        return $this->parseXml($response);
    }

    /**
     * Get the seat map for a flight.
     */
    public function getSeatMap(string $fltNo, string $date, string $origin, string $destination)
    {
        $command = "SM{$fltNo}{$date}{$origin}{$destination}~x";
        $response = $this->client->runCommand($command);

        return $this->parseXml($response);
    }

    /**
     * Select a seat for a passenger.
     */
    public function selectSeat(string $rloc, int $paxNo, string $seatNo)
    {
        $command = "*{$rloc}^ST{$paxNo}/{$seatNo}^E*R~x";
        $response = $this->client->runCommand($command);

        return $this->parseXml($response);
    }

    /**
     * Void a ticket/PNR.
     */
    public function void(string $rloc, ?string $ticketNo = null)
    {
        $command = $ticketNo ? "TV{$ticketNo}" : "*{$rloc}^X1^E*R~x";
        $response = $this->client->runCommand($command);

        return $response;
    }

    /**
     * Refund a ticket.
     */
    public function refund(string $ticketNo, array $params = [])
    {
        $command = "TR{$ticketNo}";
        $response = $this->client->runCommand($command);

        return $response;
    }

    /**
     * Change a booking.
     */
    public function change(string $rloc, array $changes)
    {
        $command = "*{$rloc}^X1^E*R~x";
        $response = $this->client->runCommand($command);

        return $response;
    }

    /**
     * Check if a route is allowed for this account.
     */
    protected function isRouteAllowed(string $origin, string $destination): bool
    {
        $allowed = $this->getAvailableAirports();
        if (empty($allowed)) {
            return true;
        }

        return in_array($origin, $allowed) || in_array($destination, $allowed);
    }

    /**
     * Build pricing-only pax entry.
     */
    protected function buildPaxPricingEntry(array $passengers): string
    {
        $entries = [];
        foreach ($passengers as $i => $pax) {
            $surname = strtoupper($pax['surname'] ?? 'TEST');
            $firstname = strtoupper($pax['firstname'] ?? 'PAX');
            $title = strtoupper($pax['title'] ?? 'MR');
            $entries[] = "-1{$surname}/{$firstname}{$title}";
        }

        return implode('^', $entries);
    }

    /**
     * Build passenger info string.
     */
    protected function buildPaxInfo(array $passengers): string
    {
        $entries = [];
        foreach ($passengers as $i => $pax) {
            $paxNo = $i + 1;
            $title = $pax['title'] ?? 'MR';
            $surname = strtoupper($pax['surname'] ?? '');
            $firstname = strtoupper($pax['firstname'] ?? '');
            $entries[] = "-{$paxNo}@{$surname}/{$firstname}{$title}";

            if (isset($pax['email'])) {
                $entries[] = "9-{$paxNo}E*{$pax['email']}";
            }
            if (isset($pax['phone'])) {
                $entries[] = "9-{$paxNo}M*{$pax['phone']}";
            }
        }

        return implode('^', $entries);
    }

    /**
     * Build flight segments string.
     */
    protected function buildFlightSegments(array $itinerary): string
    {
        $entries = [];
        foreach ($itinerary as $segment) {
            $fltNo = $segment['flt_no'];
            $class = $segment['class'];
            $date = $segment['date'];
            $origin = $segment['origin'];
            $dest = $segment['dest'];
            $qty = $segment['qty'] ?? 1;
            $entries[] = "0{$this->getIataCode()}{$fltNo}{$class}{$date}{$origin}{$dest}NN{$qty}";
        }

        return implode('^', $entries);
    }

    /**
     * Parse XML response safely.
     */
    protected function parseXml(string $xmlContent)
    {
        try {
            return new SimpleXMLElement($xmlContent);
        } catch (Exception $e) {
            return $xmlContent;
        }
    }
}
