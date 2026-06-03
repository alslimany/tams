<?php

namespace App\Services\Airline\Videcom;

use App\DTOs\Airline\RoundTripPriceRequest;
use App\DTOs\Airline\RoundTripPriceResult;
use App\Jobs\UpdateAirlineBalanceJob;
use App\Models\User;
use App\Services\Airline\AirlineProviderInterface;
use App\Services\Videcom\VidecomOrderParser;
use Carbon\Carbon;
use Exception;
use Illuminate\Container\Container;
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

        $command = "A{$date}{$origin}{$destination}~X";

        if ($isReturn && $returnDate) {
            // ~X returns multiple weeks; return leg filters by date on its own search call.
            // No special command modification needed.
        }

        $response = $this->client->runCommand($command);
        $xml = $this->parseXml($response);

        if (! $xml instanceof SimpleXMLElement) {
            return [];
        }

        $options = $xml->getName() === 'AvailabilityResponse'
            ? VidecomResponseParser::parseXAvailability($xml, $this->getIataCode(), $this->getName(), $this->getCurrency())
            : VidecomResponseParser::parseAvailability($xml, $this->getIataCode(), $this->getName());

        // Warm route/class cache from all returned dates first.
        // Pricing cache key is intentionally date-agnostic, so a priced date can seed
        // a sold-out date of the same airline+route+class.
        $this->warmRouteClassPriceCache($options);

        $requestedDate = Carbon::parse($rawDate)->toDateString();
        $options = array_values(array_filter($options, function ($option) use ($requestedDate) {
            $departureDate = Carbon::parse($option->departure_time)->toDateString();

            return $departureDate === $requestedDate;
        }));

        // Update pricing based on passenger types and cache
        foreach ($options as $option) {
            $this->applyAccuratePricing($option, $adults, $children, $infants);
        }

        $options = array_values(array_filter($options, function ($option) {
            return (float) ($option->pricing['total'] ?? 0) > 0;
        }));

        return $options;
    }

    public function searchReturnLeg(array $params)
    {
        return $this->searchAvailability($params);
    }

    /**
     * Warm price cache for unique airline+route+class combinations using the best candidate.
     */
    protected function warmRouteClassPriceCache(array $options): void
    {
        $candidates = [];

        foreach ($options as $option) {
            $class = $option->segments[0]['class'] ?? null;
            if (! $class) {
                continue;
            }

            $key = implode(':', [
                $this->getIataCode(),
                strtoupper((string) $option->departure_airport),
                strtoupper((string) $option->arrival_airport),
                strtoupper((string) $class),
            ]);

            if (! isset($candidates[$key])) {
                $candidates[$key] = $option;

                continue;
            }

            // Prefer an option that has seats, as pricing probes are more reliable there.
            if (($candidates[$key]->available_seats ?? 0) <= 0 && ($option->available_seats ?? 0) > 0) {
                $candidates[$key] = $option;
            }
        }

        foreach ($candidates as $candidate) {
            $class = $candidate->segments[0]['class'] ?? null;
            if (! $class) {
                continue;
            }

            // Skip pricing probe entirely when no seats are available — it will always fail.
            if (($candidate->available_seats ?? 0) <= 0) {
                continue;
            }

            $this->prefetchPrices($candidate, $class);
        }
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

        // Attach baggage allowance and fare ID if cached
        $baggage = PricingCacheService::getBaggage($this->getIataCode(), $option->departure_airport, $option->arrival_airport, $class);
        if ($baggage) {
            $option->pricing['hold_weight'] = $baggage['hold_weight'];
            $option->pricing['hand_weight'] = $baggage['hand_weight'];
            $option->pricing['hold_pieces'] = $baggage['hold_pieces'];
            if (! empty($baggage['fare_id'])) {
                $option->pricing['fare_id'] = $baggage['fare_id'];
            }
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
                if ($type === '_baggage') {
                    PricingCacheService::putBaggage($airline, $origin, $dest, $class, $data);
                } else {
                    PricingCacheService::put($airline, $origin, $dest, $class, $type, $data);
                }
            }
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to fetch consolidated prices for $airline $origin-$dest ($class): ".$e->getMessage());

            // Cache a sentinel so subsequent calls within this request don't retry the same failing probe.
            foreach (['AD', 'CH', 'IN'] as $type) {
                if (PricingCacheService::get($airline, $origin, $dest, $class, $type) === null) {
                    PricingCacheService::put($airline, $origin, $dest, $class, $type, false);
                }
            }
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

        $date = $this->normalizeDateToken($option->departure_time);
        $flightNumber = $this->normalizeFlightNumber((string) ($option->flight_number ?? ''));
        $classCode = $this->normalizeClassCode($class);
        $origin = $this->normalizeAirportCode((string) ($option->departure_airport ?? ''), 'TIP');
        $destination = $this->normalizeAirportCode((string) ($option->arrival_airport ?? ''), 'BEN');

        // NN (confirmed) count is 2 (AD + CH) - infants are Lap children and don't take a seat
        $flightEntry = "0{$this->getIataCode()}{$flightNumber}{$classCode}{$date}{$origin}{$destination}NN2";

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

                // Baggage is per-flight (not per pax type) — capture once from AD (Pax 1)
                if ($paxIndex === 1) {
                    // Extract numeric fare ID from FQItin e.g. "SITI 384" → "384"
                    // Try xpath first (most reliable), then direct child, then FareStore attribute
                    $fareId = null;
                    $fqiRaw = '';

                    $fqiNodes = $xml->xpath('//FareQuote/FQItin[@FQI]') ?: [];
                    if (! empty($fqiNodes)) {
                        $fqiRaw = (string) ($fqiNodes[0]['FQI'] ?? '');
                    } elseif (isset($xml->FareQuote->FQItin)) {
                        $fqiRaw = (string) ($xml->FareQuote->FQItin['FQI'] ?? '');
                    } elseif (isset($fs['FQI'])) {
                        $fqiRaw = (string) $fs['FQI'];
                    }

                    if ($fqiRaw !== '' && preg_match('/(\d+)\s*$/', $fqiRaw, $m)) {
                        $fareId = $m[1];
                    }

                    $results['_baggage'] = [
                        'hold_weight' => (string) ($fs->SegmentFS['HoldWt'] ?? ''),
                        'hand_weight' => (string) ($fs->SegmentFS['HandWt'] ?? ''),
                        'hold_pieces' => (string) ($fs->SegmentFS['HoldPcs'] ?? ''),
                        'fare_id' => $fareId,
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Get pricing for a selected itinerary.
     */
    /**
     * Fetch and clean fare rules text for a given fare ID.
     * Runs the VRS FN (Fare Note) command and strips the standard header/footer lines.
     */
    public function getFareRules(string $fareId): string
    {
        $response = $this->client->runCommand("FN{$fareId}");

        // Strip header:  "* * * Fare Rules for Fare ID [#### 384] * * *"
        // Strip footer:  "End of list"
        $lines = explode("\n", $response);
        $cleaned = [];
        $inRules = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_contains($trimmed, 'Fare Rules for Fare ID')) {
                $inRules = true;

                continue;
            }

            if (! $inRules) {
                continue;
            }

            if (preg_match('/^end\s+of\s+list/i', $trimmed)) {
                break;
            }

            // Skip the standalone "Rules" heading line some providers emit
            if (strtolower($trimmed) === 'rules') {
                continue;
            }

            $cleaned[] = $line;
        }

        return trim(implode("\n", $cleaned));
    }

    public function getPricing(array $itinerary, array $passengers)
    {
        $paxEntry = $this->buildPaxPricingEntry($passengers);

        $flightEntries = [];
        foreach ($itinerary as $segment) {
            $fltNo = $this->normalizeFlightNumber((string) ($segment['flt_no'] ?? ''));
            $class = $this->normalizeClassCode((string) ($segment['class'] ?? 'Y'));
            $date = $this->normalizeDateToken($segment['date'] ?? now());
            $origin = $this->normalizeAirportCode((string) ($segment['origin'] ?? ''), 'TIP');
            $dest = $this->normalizeAirportCode((string) ($segment['dest'] ?? ''), 'BEN');
            $status = 'NN'; // Quote Only
            $flightEntries[] = "0{$this->getIataCode()}{$fltNo}{$class}{$date}{$origin}{$dest}{$status}{$paxCount}";
        }

        $command = "i^{$paxEntry}^".implode('^', $flightEntries).'^FG^*r~x';

        $response = $this->client->runCommand($command);

        return $this->parseXml($response);
    }

    public function priceRoundTrip(RoundTripPriceRequest $request)
    {
        $outbound = $request->outboundSegment;
        $return = $request->returnSegment;

        $origin = $this->normalizeAirportCode((string) ($outbound['origin'] ?? ''), 'TIP');
        $destination = $this->normalizeAirportCode((string) ($outbound['destination'] ?? ''), 'BEN');
        $outboundFlightNo = $this->normalizeFlightNumber((string) ($outbound['flight_number'] ?? ''));
        $returnFlightNo = $this->normalizeFlightNumber((string) ($return['flight_number'] ?? ''));
        $outboundClass = $this->normalizeClassCode((string) ($outbound['class'] ?? 'Y'));
        $returnClass = $this->normalizeClassCode((string) ($return['class'] ?? 'Y'));
        $outboundDate = $this->normalizeDateToken((string) ($outbound['date'] ?? now()));
        $returnDate = $this->normalizeDateToken((string) ($return['date'] ?? now()->addDay()));

        $adults = (int) ($request->passengers['adults'] ?? 1);
        $children = (int) ($request->passengers['children'] ?? 0);
        $infants = (int) ($request->passengers['infants'] ?? 0);
        $seatQty = max(1, $adults + $children);

        $paxInfo = $this->buildPricingPaxEntry($adults, $children, $infants);

        // NN (confirmed) avoids open-route rejection on restricted classes.
        $outboundEntry = "0{$this->getIataCode()}{$outboundFlightNo}{$outboundClass}{$outboundDate}{$origin}{$destination}NN{$seatQty}";
        $returnEntry = "0{$this->getIataCode()}{$returnFlightNo}{$returnClass}{$returnDate}{$destination}{$origin}NN{$seatQty}";
        $command = "i^{$paxInfo}^{$outboundEntry}^{$returnEntry}^FG^FS1^*r~x";

        $response = $this->client->runCommand($command);
        $xml = $this->parseXml($response);

        if (! $xml instanceof SimpleXMLElement || ! isset($xml->FareQuote)) {
            throw new Exception('Invalid round-trip pricing response from provider.');
        }

        $currency = strtoupper($this->getCurrency());
        $returnLegPrice = 0.0;
        $totalPrice = 0.0;
        $taxes = [];

        $segmentTotals = [];
        $fareStores = $xml->xpath('//FareQuote/FareStore') ?: [];

        foreach ($fareStores as $fareStore) {
            $paxCode = trim((string) ($fareStore['Pax'] ?? ''));
            if ($paxCode === '') {
                continue;
            }

            $storeCurrency = trim((string) ($fareStore['Cur'] ?? ''));
            if ($storeCurrency !== '') {
                $currency = $storeCurrency;
            }

            $segmentNodes = $fareStore->SegmentFS ?? [];
            foreach ($segmentNodes as $segmentNode) {
                $segmentNumber = (int) ($segmentNode['Seg'] ?? 0);
                if ($segmentNumber <= 0) {
                    continue;
                }

                $fare = (float) ($segmentNode['Fare'] ?? 0);
                $tax = (float) ($segmentNode['Tax1'] ?? 0) + (float) ($segmentNode['Tax2'] ?? 0) + (float) ($segmentNode['Tax3'] ?? 0);
                $segmentTotal = $fare + $tax;

                if (! isset($segmentTotals[$segmentNumber])) {
                    $segmentTotals[$segmentNumber] = ['total' => 0.0, 'tax' => 0.0];
                }

                $segmentTotals[$segmentNumber]['total'] += $segmentTotal;
                $segmentTotals[$segmentNumber]['tax'] += $tax;
            }
        }

        if ($segmentTotals !== []) {
            ksort($segmentTotals);
            $orderedSegments = array_values($segmentTotals);
            $totalPrice = (float) collect($orderedSegments)->sum('total');

            $returnSegment = $segmentTotals[2] ?? ($orderedSegments[1] ?? null);
            $returnLegPrice = (float) ($returnSegment['total'] ?? 0);
            $taxes = collect($orderedSegments)->pluck('tax')->all();
        } else {
            $fareQuoteNodes = $xml->xpath('//FareQuote/FQItin') ?: [];

            $fqItins = collect($fareQuoteNodes)->map(fn (SimpleXMLElement $entry): array => [
                'segment' => (int) ($entry['Seg'] ?? 0),
                'currency' => (string) ($entry['Cur'] ?? ''),
                'total' => (float) ($entry['Total'] ?? 0),
                'tax' => (float) ($entry['Tax1'] ?? 0) + (float) ($entry['Tax2'] ?? 0) + (float) ($entry['Tax3'] ?? 0),
            ])->values();

            $currency = (string) ($fqItins->first()['currency'] ?? $currency);
            $totalPrice = (float) $fqItins->sum('total');

            if ($fqItins->count() >= 2) {
                $returnFare = $fqItins->firstWhere('segment', 2) ?? $fqItins->get(1);
                $returnLegPrice = (float) ($returnFare['total'] ?? 0);
            } elseif ($fqItins->count() === 1) {
                $outboundPrice = (float) ($request->outboundPrice ?? 0);
                $combined = (float) ($fqItins->first()['total'] ?? 0);
                $returnLegPrice = max(0, $combined - $outboundPrice);
                $totalPrice = $combined;
            }

            $taxes = $fqItins->pluck('tax')->all();
        }

        return new RoundTripPriceResult(
            returnLegPrice: $returnLegPrice,
            currency: $currency,
            totalPrice: $totalPrice,
            taxes: $taxes,
        );
    }

    public function canBookOpenReservation(array $segment): bool
    {
        $origin = strtoupper((string) ($segment['origin'] ?? $segment['departure_airport'] ?? ''));
        $destination = strtoupper((string) ($segment['dest'] ?? $segment['arrival_airport'] ?? ''));
        $class = strtoupper(substr((string) ($segment['class'] ?? 'Y'), 0, 1));

        if ($origin === '' || $destination === '' || $class === '') {
            return false;
        }

        return OpenReservationCacheService::remember(
            $this->getIataCode(),
            $origin,
            $destination,
            $class,
            function () use ($segment, $origin, $destination, $class): bool {
                try {
                    $date = $this->normalizeDateToken($segment['date'] ?? $segment['departure_time'] ?? now());
                    $fltNo = $this->normalizeFlightNumber((string) ($segment['flt_no'] ?? $segment['flight_number'] ?? ''));
                    $paxEntry = '-1TEST/PAXMR';
                    $flightEntry = "0{$this->getIataCode()}{$fltNo}{$class}{$date}{$origin}{$destination}NN1";
                    $command = "i^{$paxEntry}^{$flightEntry}^FG^*r~x";
                    $response = $this->client->runCommand($command);
                    $xml = $this->parseXml($response);

                    if (is_string($xml)) {
                        return ! $this->isOpenReservationErrorResponse($xml);
                    }

                    if (! ($xml instanceof SimpleXMLElement) || ! isset($xml->FareQuote)) {
                        return false;
                    }

                    $rawXml = (string) ($xml->asXML() ?: '');
                    if ($this->isOpenReservationErrorResponse($rawXml)) {
                        return false;
                    }

                    $fareStore = $xml->FareQuote->FareStore[0] ?? null;
                    $fareQuote = $xml->FareQuote->FQItin[0] ?? null;
                    $total = (float) ($fareStore['Total'] ?? $fareQuote['Total'] ?? 0);

                    if ($total > 0) {
                        return true;
                    }

                    // Some providers return valid open-reservation eligibility without a priced FareStore.
                    $itineraryRows = $xml->xpath('//Itinerary/Itin') ?: [];

                    return count($itineraryRows) > 0;
                } catch (\Throwable $exception) {
                    report($exception);

                    return false;
                }
            }
        );
    }

    protected function isOpenReservationErrorResponse(string $payload): bool
    {
        $normalized = strtolower(trim($payload));

        if ($normalized === '') {
            return true;
        }

        return str_contains($normalized, 'error:')
            || str_contains($normalized, 'cannot be booked as open')
            || str_contains($normalized, 'invalid entry')
            || str_contains($normalized, 'not authorised')
            || str_contains($normalized, 'not authorized')
            || str_contains($normalized, 'failed');
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

        $agencyInfo = 'FG^FS1^MI-ABC TOURS01012';

        $commandString = $this->previewBookingCommand($params);

        $response = $this->client->runCommand($commandString);

        return $this->parseXml($response);
    }

    public function previewBookingCommand(array $params): string
    {
        $passengers = $params['passengers'] ?? [];
        $resType = $params['reservation_type'] ?? 'NN';
        $paxInfo = $this->buildPaxInfo($passengers);
        $contactInfo = $this->buildContactInfo($params['contact'] ?? [], count($passengers));
        $flightSegments = $this->buildFlightSegments($params['itinerary'] ?? [], count($passengers), $resType);
        $apfaxInfo = $this->buildApfaxInfo($passengers, $params['extras'] ?? []);
        $ancillaryInfo = $this->buildAncillaryCommands($params['extras'] ?? [], $passengers, $params['itinerary'] ?? []);
        $timeLimit = $this->buildTimeLimitCommand();

        $agencyInfo = 'FG^FS1^MI-ABC TOURS01012';

        return $this->buildIssuanceCommand(
            paxInfo: $paxInfo,
            contactInfo: $contactInfo,
            flightSegments: $flightSegments,
            apfaxInfo: $apfaxInfo,
            ancillaryInfo: $ancillaryInfo,
            agencyInfo: $agencyInfo,
            timeLimit: $timeLimit,
        );
    }

    protected function buildIssuanceCommand(
        string $paxInfo,
        string $contactInfo,
        string $flightSegments,
        string $apfaxInfo,
        string $ancillaryInfo,
        string $agencyInfo,
        ?string $timeLimit = null,
    ): string {
        $commands = array_filter([
            $paxInfo,
            $contactInfo,
            $flightSegments,
            $apfaxInfo,
            $ancillaryInfo,
            $agencyInfo,
            $timeLimit,
            'EZT*R',
            'EZRE',
        ]);

        return 'i^'.implode('^', $commands).'^*R~x';
    }

    public function retrieveBooking(string $rloc)
    {
        return $this->queryPnr($rloc);
    }

    public function queryPnr(string $pnr)
    {
        $response = $this->client->runCommand("*{$pnr}~X");

        return $this->parseXml($response);
    }

    /**
     * Issue tickets for a booking.
     */
    public function issueTicket(string $rloc, array $paymentInfo)
    {
        $command = "*{$rloc}^MM^EZT*R^EZRE^*R~x";
        $response = $this->client->runCommand($command);
        $xml = $this->parseXml($response);

        if (! $xml instanceof SimpleXMLElement) {
            return $xml;
        }

        $result = [
            'xml' => $xml,
            'parsed' => null,
            'order_id' => null,
        ];

        try {
            $parser = app(VidecomOrderParser::class);
            $parsed = $parser->parse($xml->asXML() ?: $response);

            $paymentType = strtolower((string) ($paymentInfo['type'] ?? ''));
            if ($paymentType !== '') {
                $parsed->paymentMethod = $paymentType;
            }

            $result['parsed'] = $parsed->toArray();
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $result;
    }

    /**
     * Fetch current agency ticket wallet balance from Videcom office command.
     *
     * @return array{currency: string, balance: float}
     */
    public function fetchWalletBalance(?string $preferredCurrency = null): array
    {
        $response = $this->client->runCommand('zua~x');
        $xml = $this->parseXml($response);

        if (! $xml instanceof SimpleXMLElement) {
            return [
                'currency' => strtoupper((string) ($preferredCurrency ?: $this->getCurrency())),
                'balance' => 0.0,
            ];
        }

        $limits = $xml->xpath('//ticketmoneylimit');
        if (! is_array($limits) || $limits === []) {
            return [
                'currency' => strtoupper((string) ($preferredCurrency ?: $this->getCurrency())),
                'balance' => 0.0,
            ];
        }

        $preferred = strtoupper((string) ($preferredCurrency ?: ''));
        foreach ($limits as $limit) {
            $current = strtoupper((string) ($limit['cur'] ?? ''));
            if ($preferred !== '' && $current !== $preferred) {
                continue;
            }

            return [
                'currency' => $current !== '' ? $current : strtoupper((string) ($preferredCurrency ?: $this->getCurrency())),
                'balance' => (float) ($limit['limit'] ?? 0),
            ];
        }

        $first = $limits[0];

        return [
            'currency' => strtoupper((string) ($first['cur'] ?? ($preferredCurrency ?: $this->getCurrency()))),
            'balance' => (float) ($first['limit'] ?? 0),
        ];
    }

    protected function resolveIssuerContext(array $paymentInfo): ?User
    {
        $user = $paymentInfo['user'] ?? null;
        if ($user instanceof User) {
            return $user;
        }

        $userId = $paymentInfo['user_id'] ?? null;
        if (is_numeric($userId)) {
            return User::query()->find((int) $userId);
        }

        return null;
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
        $command = $ticketNo ? "TV{$ticketNo}" : "*{$rloc}^EZV*R^*R~x";
        $response = $this->client->runCommand($command);

        return $response;
    }

    /**
     * Build the FCR/FCC/X segment cancellation portion of a refund command.
     * Segments are processed in reverse order (highest → lowest) so that removing
     * a segment does not shift the index of remaining segments.
     *
     * For YI (Oya) the FCR/FCC fare-quote commands are skipped — only X{n} is sent.
     *
     * @return string e.g. "^FCR2^FCC2^X2^FCR1^FCC1^X1" or "^X2^X1" for YI
     */
    protected function buildRefundSegmentCommands(int $segmentCount): string
    {
        $parts = [];

        for ($seg = $segmentCount; $seg >= 1; $seg--) {
            if ($this->getIataCode() !== 'YI') {
                $parts[] = "FCR{$seg}";
                $parts[] = "FCC{$seg}";
            }

            $parts[] = "X{$seg}";
        }

        return '^'.implode('^', $parts);
    }

    /**
     * Get a refund quote (penalties + refundable amount) without executing the refund.
     *
     * Command: *{PNR}^FCR1^FCC1^X1^FSM^*R~X
     *
     * @return array{mps_penalties: array<int, array<string, mixed>>, refund_amount: float, penalty_amount: float, currency: string}
     */
    public function refundQuote(string $pnr, int $segmentCount): array
    {
        $segmentCommands = $this->buildRefundSegmentCommands($segmentCount);
        $command = "*{$pnr}{$segmentCommands}^FSM^*R~X";

        $response = $this->client->runCommand($command);

        $xml = $this->parseXml($response);

        if (! $xml instanceof SimpleXMLElement) {
            return [
                'mps_penalties' => [],
                'refund_amount' => 0.0,
                'penalty_amount' => 0.0,
                'currency' => $this->getCurrency(),
            ];
        }

        return VidecomPnrParser::parseRefundQuote($xml);
    }

    /**
     * Execute a refund for a PNR.
     *
     * Command: *{PNR}^FCR1^FCC1^X1^FSM^REF*^RI{penaltyAmount}*R~X
     * If penalty is 0 the RI part is omitted: *{PNR}^...^FSM^REF*^*R~X
     *
     * Returns a structured array with the plain-text response parsed.
     *
     * @return array{success: bool, raw_response: string, tickets_issued: array<int, string>}
     */
    public function refund(string $pnr, int $segmentCount, float $penaltyAmount): array
    {
        $segmentCommands = $this->buildRefundSegmentCommands($segmentCount);

        $penaltyAmount = round(abs($penaltyAmount), 2);

        if ($penaltyAmount > 0) {
            $command = "*{$pnr}{$segmentCommands}^FSM^REF*^RI{$penaltyAmount}*R~X";
        } else {
            $command = "*{$pnr}{$segmentCommands}^FSM^REF*^*R~X";
        }

        $response = $this->client->runCommand($command);

        $rawText = is_string($response) ? $response : (string) ($response->response ?? '');

        $this->dispatchDelayedAirlineBalanceUpdate();

        return VidecomPnrParser::parseRefundExecuteResponse($rawText);
    }

    /**
     * Get a change quote (outstanding amount) for swapping a segment without committing.
     *
     * Command: *{RLOC}^X{segLine}^{newSegmentCode}^FG^FS1^MB^*R~X
     *
     * @return array{outstanding_amount: float, currency: string, change_type: string, raw_response: string}
     */
    public function changeQuote(string $rloc, int $segmentLine, string $newSegmentCode): array
    {
        $command = "*{$rloc}^X{$segmentLine}^{$newSegmentCode}^FG^FS1^MB^*R~X";

        $response = $this->client->runCommand($command);

        $rawText = is_string($response) ? $response : (string) ($response->response ?? '');

        $xml = $this->parseXml($response);

        if ($xml instanceof SimpleXMLElement) {
            $quote = VidecomPnrParser::parseChangeQuote($xml);

            return array_merge($quote, ['change_type' => 'unknown']);
        }

        // Videcom sometimes returns plain text instead of XML, e.g.:
        //   "Amount outstanding LYD390"
        //   "Amount outstanding 390 LYD"
        // Try to extract the amount and currency from the plain-text response.
        $parsed = $this->parsePlainTextChangeQuote($rawText);

        return [
            'outstanding_amount' => $parsed['amount'],
            'currency' => $parsed['currency'] ?: $this->getCurrency(),
            'change_type' => 'unknown',
            'raw_response' => $rawText,
        ];
    }

    /**
     * Extract outstanding amount and currency from a plain-text Videcom response.
     *
     * Handles patterns like:
     *   "Amount outstanding LYD390"
     *   "Amount outstanding 390 LYD"
     *   "Amount outstanding LYD 390.00"
     *
     * @return array{amount: float, currency: string}
     */
    protected function parsePlainTextChangeQuote(string $text): array
    {
        // Explicit "no charge" responses — return zero immediately.
        $normalized = strtolower(trim($text));
        if (
            str_contains($normalized, 'no amount outstanding')
            || str_contains($normalized, 'no outstanding')
            || $normalized === '0'
        ) {
            return ['amount' => 0.0, 'currency' => ''];
        }

        // Pattern 1: currency before amount — "LYD390" or "LYD 390.00"
        if (preg_match('/([A-Z]{3})\s*([\d]+(?:\.\d+)?)/i', $text, $m)) {
            return [
                'amount' => (float) $m[2],
                'currency' => strtoupper($m[1]),
            ];
        }

        // Pattern 2: amount before currency — "390 LYD" or "390.00 LYD"
        if (preg_match('/([\d]+(?:\.\d+)?)\s*([A-Z]{3})/i', $text, $m)) {
            return [
                'amount' => (float) $m[1],
                'currency' => strtoupper($m[2]),
            ];
        }

        // Pattern 3: bare number — "Amount outstanding 390"
        if (preg_match('/([\d]+(?:\.\d+)?)/', $text, $m)) {
            return [
                'amount' => (float) $m[1],
                'currency' => '',
            ];
        }

        return ['amount' => 0.0, 'currency' => ''];
    }

    /**
     * Confirm a ticket change (revalidation or reissue).
     *
     * The caller must pass the correct change_type:
     *   - 'revalidation': same origin + destination + booking class → REZT*R
     *   - 'reissue':      different route or class              → EZV*R^EZT*R
     *
     * @return array{success: bool, change_type: string, raw_response: string}
     */
    public function confirmChange(string $rloc, int $segmentLine, string $newSegmentCode, string $changeType, float $outstandingAmount = 0.0): array
    {
        // Only include ^MB (fare recalculation) when there is an outstanding charge.
        // When the change is free ("No Amount Outstanding"), omit ^MB entirely.
        $mb = $outstandingAmount > 0 ? '^MB' : '';

        $base = "*{$rloc}^X{$segmentLine}^{$newSegmentCode}^FG^FS1{$mb}";

        $command = match ($changeType) {
            'revalidation' => "{$base}^REZT*R^*R~X",
            'reissue' => "{$base}^EZV*R^EZT*R^*R~X",
            default => "{$base}^REZT*R^*R~X",
        };

        $response = $this->client->runCommand($command);

        $rawText = is_string($response) ? $response : (string) ($response->response ?? '');

        $this->dispatchDelayedAirlineBalanceUpdate();

        $result = VidecomPnrParser::parseChangeConfirmResponse($rawText);

        return array_merge($result, ['change_type' => $changeType]);
    }

    /**
     * Change a booking.
     *
     * @deprecated Use changeQuote() + confirmChange() instead.
     */
    public function change(string $rloc, array $changes)
    {
        $command = "*{$rloc}^X1^E*R~x";
        $response = $this->client->runCommand($command);
        $this->dispatchDelayedAirlineBalanceUpdate();

        return $response;
    }

    protected function dispatchDelayedAirlineBalanceUpdate(): void
    {
        $tenantProviderId = (int) ($this->config['tenant_provider_id'] ?? 0);
        if ($tenantProviderId <= 0) {
            return;
        }

        UpdateAirlineBalanceJob::dispatch($tenantProviderId)
            ->delay(now()->addMinutes(10));
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

    protected function buildPricingPaxEntry(int $adults, int $children, int $infants): string
    {
        $entries = [];

        for ($index = 0; $index < max(1, $adults); $index++) {
            $entries[] = '-1PAX/ADULTMR';
        }

        for ($index = 0; $index < $children; $index++) {
            $entries[] = '-1PAX/CHILDMR.CH10';
        }

        for ($index = 0; $index < $infants; $index++) {
            $entries[] = '-1PAX/INFMR.IN06';
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
            $type = strtoupper($pax['type'] ?? 'ADULT');
            $title = strtoupper($pax['title'] ?? 'MR');
            $surname = $this->normalizeNameToken((string) ($pax['last_name'] ?? $pax['surname'] ?? ''), 'TEST');
            $firstname = $this->normalizeNameToken((string) ($pax['first_name'] ?? $pax['firstname'] ?? ''), 'PAX');

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
            $normalizedPhone = trim((string) $phone);
            if (! str_starts_with($normalizedPhone, '+')) {
                $normalizedPhone = '+'.ltrim($normalizedPhone, '+');
            }

            $entries[] = "9-1M*{$normalizedPhone}";
        }

        if ($email) {
            $entries[] = '9-1E*'.trim((string) $email);
        }

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
        $includeDocs = (bool) ($extras['include_docs'] ?? false);

        foreach ($passengers as $i => $pax) {
            $paxNo = $i + 1;

            if ($includeDocs &&
                ! empty($pax['passport_number']) &&
                ! empty($pax['passport_issue_country']) &&
                ! empty($pax['nationality']) &&
                ! empty($pax['dob']) &&
                ! empty($pax['passport_expiry']) &&
                ! empty($pax['gender'])
            ) {
                $issueCountry = strtoupper(! empty($pax['passport_issue_country']) ? $pax['passport_issue_country'] : 'LBY');
                $nationality = strtoupper(! empty($pax['nationality']) ? $pax['nationality'] : 'LBY');
                $passNo = strtoupper($pax['passport_number']);
                $dob = Carbon::parse($pax['dob'])->format('dMy');
                $gender = strtolower(substr((string) $pax['gender'], 0, 1));
                $expiry = Carbon::parse($pax['passport_expiry'])->format('dMy');
                $surname = $this->normalizeNameToken((string) ($pax['last_name'] ?? $pax['surname'] ?? ''), 'TEST');
                $firstname = $this->normalizeNameToken((string) ($pax['first_name'] ?? $pax['firstname'] ?? ''), 'PAX');

                $entries[] = "4-{$paxNo}FDOCS/P/{$issueCountry}/{$passNo}/{$nationality}/{$dob}/{$gender}/{$expiry}/{$surname}/{$firstname}/";
            }

            if (! empty($pax['visa_number'])) {
                $visaNo = strtoupper($pax['visa_number']);
                $visaPlace = strtoupper($pax['visa_issue_country'] ?? 'LY');
                $visaDate = Carbon::parse($pax['visa_issue_date'] ?? now())->format('dMy');
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
                        '{FLTNO}' => $this->normalizeFlightNumber((string) ($segment['flt_no'] ?? '')),
                        '{ORIGIN}' => $this->normalizeAirportCode((string) ($segment['origin'] ?? ''), ''),
                        '{DEST}' => $this->normalizeAirportCode((string) ($segment['dest'] ?? ''), ''),
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
            $fltNo = $this->normalizeFlightNumber((string) ($segment['flt_no'] ?? ''));
            $class = $this->normalizeClassCode((string) ($segment['class'] ?? 'Y'));
            $date = $this->normalizeDateToken($segment['date'] ?? now());
            $origin = $this->normalizeAirportCode((string) ($segment['origin'] ?? ''), 'TIP');
            $dest = $this->normalizeAirportCode((string) ($segment['dest'] ?? ''), 'BEN');

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

    protected function shouldUseClassBandsInAvailability(): bool
    {
        $override = data_get($this->config, 'availability.classbands');
        if (is_bool($override)) {
            return $override;
        }

        $airlineKeys = [
            strtoupper($this->getIataCode()),
            $this->getVidecomCode(),
        ];

        foreach ($airlineKeys as $key) {
            $container = Container::getInstance();
            if (! $container || ! $container->bound('config')) {
                continue;
            }

            $configured = config("videcom_airlines.{$key}.availability.classbands");
            if (is_bool($configured)) {
                return $configured;
            }
        }

        return true;
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

    protected function normalizeNameToken(string $value, string $fallback = 'PAX'): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z]/', '', trim($value)));

        return $normalized !== '' ? $normalized : $fallback;
    }

    protected function normalizeFlightNumber(string $value, string $fallback = '100'): string
    {
        $digits = preg_replace('/\D+/', '', trim($value));

        if ($digits === null || $digits === '') {
            $digits = preg_replace('/\D+/', '', $fallback) ?: '100';
        }

        return str_pad($digits, 4, '0', STR_PAD_LEFT);
    }

    protected function normalizeClassCode(string $value, string $fallback = 'Y'): string
    {
        $normalized = strtoupper(substr(preg_replace('/\s+/', '', trim($value)), 0, 1));

        return $normalized !== '' ? $normalized : $fallback;
    }

    protected function normalizeDateToken(mixed $value): string
    {
        return strtoupper(Carbon::parse($value ?? now())->format('dM'));
    }

    protected function normalizeAirportCode(string $value, string $fallback = 'TIP'): string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($value)));

        return $normalized !== '' ? $normalized : $fallback;
    }
}
