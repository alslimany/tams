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
     * @param array $config Airline credentials and configuration
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
        $date = $params['date'] ?? now()->format('dMY');
        $origin = strtoupper($params['origin'] ?? '');
        $destination = strtoupper($params['destination'] ?? '');
        $qty = $params['qty'] ?? 1;
        $isReturn = $params['is_return'] ?? false;

        // Check if origin/dest are allowed for this account
        if (!$this->isRouteAllowed($origin, $destination)) {
            throw new Exception("Route {$origin}-{$destination} is not allowed for this airline account.");
        }

        $command = "A{$date}{$origin}{$destination}[SalesCity={$origin},VARS=True,ClassBands=True,StartCity={$origin},SingleSeg=" . ($isReturn ? 'r' : 's') . ",FGNoAv=True,qtyseats={$qty}]";
        
        $response = $this->client->runCommand($command);
        return $this->parseXml($response);
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

        $command = "i^{$paxEntry}^" . implode('^', $flightEntries) . "^FG^FS1^*r~x";
        
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
        
        // Default booking pattern
        $command = "{$paxInfo}^{$flightSegments}^FG^FS1^MM^*R~x";
        
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
    public function void(string $rloc, string $ticketNo = null)
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
        if (empty($allowed)) return true;

        return in_array($origin, $allowed) || in_array($destination, $allowed);
    }

    /**
     * Build pricing-only pax entry.
     */
    protected function buildPaxPricingEntry(array $passengers): string
    {
        $count = count($passengers);
        return "-{$count}Pax/A#/B#"; // Default simple entry
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
