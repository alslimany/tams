<?php

namespace App\Services\Airline\Videcom;

use App\Services\Airline\AirlineProviderInterface;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
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

    public function getAncillaryCatalog(array $flight = [], array $searchParams = []): array
    {
        return VidecomAncillaryCatalog::forAirline($this->getIataCode(), $this->config);
    }

    /**
     * Search for flight availability.
     */
    public function searchAvailability(array $params)
    {
        $rawDate = $params['date'] ?? now()->toDateTimeString();
        $date = strtoupper(\Carbon\Carbon::parse($rawDate)->format('dM'));
        
        $returnDateRaw = $params['return_date'] ?? null;
        $returnDate = $returnDateRaw ? strtoupper(\Carbon\Carbon::parse($returnDateRaw)->format('dM')) : null;

        $origin = strtoupper($params['origin'] ?? '');
        $destination = strtoupper($params['destination'] ?? '');
        $qty = $params['qty'] ?? 1;
        $isReturn = filter_var($params['is_return'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $adults = (int) ($params['adults'] ?? 1);
        $children = (int) ($params['children'] ?? 0);
        $infants = (int) ($params['infants'] ?? 0);

        // Check if origin/dest are allowed for this account
        if (! $this->isRouteAllowed($origin, $destination)) {
            throw new Exception("Route {$origin}-{$destination} is not allowed for this airline account.");
        }

        $command = "A{$date}{$origin}{$destination}[SalesCity={$origin},VARS=True,ClassBands=True,StartCity={$origin},SingleSeg=".($isReturn ? 'r' : 's').",FGNoAv=True,qtyseats={$qty}";
        
        if ($isReturn && $returnDate) {
            $command .= ",RetDate={$returnDate}";
        }
        
        $command .= "]";

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
            $fltNo = str_pad(preg_replace('/[^0-9]/', '', $segment['flt_no'] ?? '100'), 4, '0', STR_PAD_LEFT);
            $class = substr($segment['class'] ?? 'Y', 0, 1);
            $date = strtoupper(\Carbon\Carbon::parse($segment['date'] ?? now())->format('dM'));
            $origin = strtoupper($segment['origin'] ?? 'TIP');
            $dest = strtoupper($segment['dest'] ?? 'BEN');
            $status = 'NN'; // Quote Only
            $flightEntries[] = "0{$this->getIataCode()}{$fltNo}{$class}{$date}{$origin}{$dest}{$status}{$paxCount}";
        }

        $command = "I^{$paxEntry}^".implode('^', $flightEntries).'^FG^*r~x';

        $response = $this->client->runCommand($command);

        return $this->parseXml($response);
    }

    /**
     * Create a booking (PNR).
     */
    public function createBooking(array $params)
    {
        $passengers = $params['passengers'] ?? [];
        $resType = $params['reservation_type'] ?? 'NN';
        $paxInfo = $this->buildPaxInfo($passengers);
        $contactInfo = $this->buildContactInfo($params['contact'] ?? [], count($passengers));
        $flightSegments = $this->buildFlightSegments($params['itinerary'] ?? [], count($passengers), $resType);
        $apfaxInfo = $this->buildApfaxInfo($passengers, $params['extras'] ?? []);
        $ancillaryInfo = $this->buildAncillaryCommands($params['extras'] ?? [], $passengers, $params['itinerary'] ?? []);
        $timeLimit = $this->buildTimeLimitCommand();

        // Add standard pricing/agency info from example
        $agencyInfo = 'FG^FS1^MI-ABC TOURS01012';

        $commands = array_filter([
            $paxInfo,
            $contactInfo,
            $flightSegments,
            $apfaxInfo,
            $ancillaryInfo,
            $agencyInfo,
            $timeLimit,
            'EZT*R', // End and Ticket (from example)
            'EZRE',  // End and Receive (from example)
        ]);

        $commandString = 'I^' . implode('^', $commands) . '^*R~x';

        dd($commandString);

        $response = $this->client->runCommand($commandString);

        return $this->parseXml($response);
    }

    public function retrieveBooking(string $rloc)
    {
        $response = $this->client->runCommand("*{$rloc}~x");

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
    public function getSeatMap(string $fltNo, string $date)
    {
        $fltNo = str_pad(preg_replace('/[^0-9]/', '', $fltNo), 4, '0', STR_PAD_LEFT);
        $formattedDate = strtoupper(\Carbon\Carbon::parse($date)->format('dM'));

        // Use LS command for XML details (e.g. LSYL0102/03APR~X)
        $command = "LS{$this->getIataCode()}{$fltNo}/{$formattedDate}~X";
        $response = $this->client->runCommand($command);

        $xml = $this->parseXml($response);

        if (! ($xml instanceof SimpleXMLElement) || ! isset($xml->Seat)) {
            return ['seats' => [], 'cabins' => []];
        }

        $seatMap = [];
        $cabins = [];

        if (isset($xml->CabinCount->Cabin)) {
            foreach ($xml->CabinCount->Cabin as $cabin) {
                $cabins[] = [
                    'class' => (string) $cabin['CabinClass'],
                    'seats' => (int) $cabin['Seats'],
                ];
            }
        }

        // We want to calculate the maximum rows and columns to help the frontend build a grid
        $maxRow = 0;
        $maxCol = 0;

        foreach ($xml->Seat as $seat) {
            $row = (int) $seat['Row'];
            $col = (int) $seat['Col'];

            $maxRow = max($maxRow, $row);
            $maxCol = max($maxCol, $col);

            // Is the seat currently occupied or blocked?
            $seatId = (int) ($seat['SeatID'] ?? 0);
            $isOccupied = $seatId > 0 || ! empty((string) $seat['Status']) || ! empty((string) $seat['RLOC']);
            $isBlocked = ((string) $seat['CellDescription']) === 'Block Seat';
            $isAisle = ((string) $seat['CellDescription']) === 'Aisle' || str_contains((string) $seat['CellDescription'], 'WidthMarker');

            $seatMap[] = [
                'row' => $row,
                'col' => $col,
                'seat_id' => $seatId,
                'code' => (string) $seat['Code'],
                'cabinType' => (string) $seat['CabinClass'],
                'description' => (string) $seat['CellDescription'],
                'no_infant' => ((string) $seat['NoInfantSeat']) === 'True',
                'prm' => ((string) $seat['PRMSeat']) === 'True',
                'is_occupied' => $isOccupied || $isBlocked,
                'is_aisle' => $isAisle,
                'price' => (float) ($seat['scprice'] ?? 0),
            ];
        }

        return [
            'grid' => [
                'max_row' => $maxRow,
                'max_col' => $maxCol,
            ],
            'cabins' => $cabins,
            'seats' => $seatMap,
        ];
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
        return $this->buildPaxInfo($passengers);
    }

    /**
     * Build passenger info string.
     */
    protected function buildPaxInfo(array $passengers): string
    {
        $entries = [];
        foreach ($passengers as $i => $pax) {
            $type = strtoupper($pax['type'] ?? 'ADULT');
            $title = strtoupper($pax['title'] ?? 'MR');
            $surname = strtoupper(preg_replace('/[^A-Z]/', '', $pax['last_name'] ?? $pax['surname'] ?? 'TEST'));
            $firstname = strtoupper(preg_replace('/[^A-Z]/', '', $pax['first_name'] ?? $pax['firstname'] ?? 'PAX'));

            // Format: -1LASTNAME/FIRSTNAME TITLE
            $entry = "-1{$surname}/{$firstname}{$title}";

            // Append PTC and Age if child or infant
            if (in_array($type, ['CHILD', 'CHD', 'CH'])) {
                $age = str_pad((string) ($pax['age'] ?? 9), 2, '0', STR_PAD_LEFT);
                $entry .= ".CH{$age}";
            } elseif (in_array($type, ['INFANT', 'INF', 'IN'])) {
                $ageMonths = str_pad((string) ($pax['age_months'] ?? 6), 2, '0', STR_PAD_LEFT);
                $entry .= ".IN{$ageMonths}";
            }

            $entries[] = $entry;
        }

        return implode('^', $entries);
    }

    /**
     * Build general contact info string.
     */
    protected function buildContactInfo(array $contact, int $paxCount): string
    {
        $entries = [];
        $email = $contact['email'] ?? null;
        $phone = $contact['phone'] ?? null;

        if ($phone) {
            // Format: 9-1M*[phone] (from example)
            $entries[] = "9-1M*{$phone}";
        }

        if ($email) {
            // Format: 9-1E*[email] (from example)
            $entries[] = "9-1E*{$email}";
        }

        // Ensure at least one contact exists, else dummy
        if (empty($entries) && $paxCount > 0) {
            $entries[] = '9-1T*TRAVEL AGENCY';
        }

        return implode('^', $entries);
    }

    /**
     * Build APFAX string (Passport, Visa, Meal, Seats).
     */
    protected function buildApfaxInfo(array $passengers, array $extras): string
    {
        $entries = [];
        $seatSelections = $extras['seats'] ?? [];

        foreach ($passengers as $i => $pax) {
            $paxNo = $i + 1;

            // 1. DOCS (Passport)
            // FORMAT: 4-[PAX]FDOCS/P/[ISSUE_COUNTRY]/[PASSPORT]/[NATIONALITY]/[DOB]/[GENDER]/[EXPIRY]/[SURNAME]/[FIRSTNAME]/
            if (! empty($pax['passport_number'])) {
                $issueCountry = strtoupper(! empty($pax['passport_issue_country']) ? $pax['passport_issue_country'] : 'LBY');
                $nationality = strtoupper(! empty($pax['nationality']) ? $pax['nationality'] : 'LBY');
                $passNo = strtoupper($pax['passport_number']);
                $dob = \Carbon\Carbon::parse($pax['dob'] ?? '1990-01-01')->format('dMy'); // e.g., 14Mar78
                $gender = strtolower(substr($pax['gender'] ?? 'M', 0, 1)); // m or f
                $expiry = \Carbon\Carbon::parse($pax['passport_expiry'] ?? '2030-01-01')->format('dMy');
                $surname = strtoupper(preg_replace('/[^A-Z]/', '', $pax['last_name'] ?? $pax['surname'] ?? 'TEST'));
                $firstname = strtoupper(preg_replace('/[^A-Z]/', '', $pax['first_name'] ?? $pax['firstname'] ?? 'PAX'));

                $entries[] = "4-{$paxNo}FDOCS/P/{$issueCountry}/{$passNo}/{$nationality}/{$dob}/{$gender}/{$expiry}/{$surname}/{$firstname}/";
            }

            // 2. DOCO (Visa)
            // FORMAT: 4-[PAX]FDOCO//V/[VISA_NO]/[PLACE]/[DATE]/[COUNTRY] OR NA
            if (! empty($pax['visa_number'])) {
                $visaNo = strtoupper($pax['visa_number']);
                $visaPlace = strtoupper($pax['visa_issue_country'] ?? 'LY');
                $visaDate = \Carbon\Carbon::parse($pax['visa_issue_date'] ?? now())->format('dMy');
                $visaApplies = strtoupper($pax['visa_destination_country'] ?? 'LY');

                $entries[] = "4-{$paxNo}FDOCO//V/{$visaNo}/{$visaPlace}/{$visaDate}/{$visaApplies}";
            }

            // 3. Seats (RQST)
            // FORMAT: 4-[PAX]S[SEG]FRQST[SEAT]
            if (! empty($seatSelections[$i])) {
                foreach ((array) $seatSelections[$i] as $segmentNo => $seatCode) {
                    $segmentIndex = (int) $segmentNo;
                    $segmentIndex = $segmentIndex > 0 ? $segmentIndex : ((int) $segmentNo + 1);

                    $entries[] = "4-{$paxNo}S{$segmentIndex}FRQST".strtoupper((string) $seatCode);
                }
            }
        }

        return implode('^', $entries);
    }

    protected function buildAncillaryCommands(array $extras, array $passengers, array $itinerary): string
    {
        $selectedServices = $extras['selected_services'] ?? [];
        $catalog = collect($this->getAncillaryCatalog())
            ->where('enabled', true)
            ->keyBy('code');

        $commands = [];

        foreach ($selectedServices as $serviceSelection) {
            $service = $catalog->get($serviceSelection['code'] ?? null);

            if (! $service || empty($service['command_template'])) {
                continue;
            }

            $quantity = max(0, (int) ($serviceSelection['quantity'] ?? 0));
            $passengerIndexes = collect($serviceSelection['passengers'] ?? [])
                ->map(fn ($index) => (int) $index)
                ->all();

            if (empty($passengerIndexes)) {
                $passengerIndexes = array_keys($passengers);
            }

            foreach ($passengerIndexes as $passengerIndex) {
                $paxNo = $passengerIndex + 1;

                foreach ($itinerary as $segmentIndex => $segment) {
                    $commands[] = strtr($service['command_template'], [
                        '{PAX}' => (string) $paxNo,
                        '{SEG}' => (string) ($segmentIndex + 1),
                        '{QTY}' => (string) $quantity,
                        '{AIRLINE}' => $this->getIataCode(),
                        '{FLTNO}' => str_pad(preg_replace('/[^0-9]/', '', $segment['flt_no'] ?? ''), 4, '0', STR_PAD_LEFT),
                        '{ORIGIN}' => strtoupper($segment['origin'] ?? ''),
                        '{DEST}' => strtoupper($segment['dest'] ?? ''),
                    ]);
                }
            }
        }

        return implode('^', array_filter($commands));
    }

    /**
     * Build flight segments string.
     */
    protected function buildFlightSegments(array $itinerary, int $qty, string $status = 'NN'): string
    {
        $entries = [];
        foreach ($itinerary as $segment) {
            $fltNo = str_pad(preg_replace('/[^0-9]/', '', $segment['flt_no'] ?? '100'), 4, '0', STR_PAD_LEFT);
            $class = substr($segment['class'] ?? 'Y', 0, 1);
            $date = \Carbon\Carbon::parse($segment['date'] ?? now())->format('dM');
            $origin = strtoupper($segment['origin'] ?? 'TIP');
            $dest = strtoupper($segment['dest'] ?? 'BEN');

            $entries[] = "0{$this->getIataCode()}{$fltNo}{$class}{$date}{$origin}{$dest}{$status}{$qty}";
        }

        return implode('^', $entries);
    }

    protected function buildTimeLimitCommand(): ?string
    {
        if (! ($this->config['supports_manual_time_limit'] ?? false)) {
            return null;
        }

        $hours = (int) ($this->config['manual_time_limit_hour'] ?? 18);
        $days = (int) ($this->config['manual_time_limit_days'] ?? 2);

        return '8/'.str_pad((string) $hours, 2, '0', STR_PAD_LEFT).'00/'.now()->addDays($days)->format('dM');
    }

    /**
     * Parse XML response safely.
     */
    protected function parseXml(string $xmlContent)
    {
        $xmlContent = trim($xmlContent);

        // If it looks like HTML, strip tags to get the error message
        if (stripos($xmlContent, '<html') !== false || stripos($xmlContent, '<body') !== false) {
            return strip_tags($xmlContent);
        }

        try {
            // Attempt to parse as XML
            if (str_starts_with($xmlContent, '<')) {
                return new SimpleXMLElement($xmlContent);
            }
        } catch (Exception $e) {
            // If it's not valid XML, return as string
            return $xmlContent;
        }

        return $xmlContent;
    }
}
