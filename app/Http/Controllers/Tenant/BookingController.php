<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Models\Tenant\Booking;
use App\Models\TenantProvider;
use App\Services\Airline\FlightSearchService;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\Videcom\VidecomAncillaryCatalog;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function __construct(
        protected FlightSearchService $searchService
    ) {}

    /**
     * Display the search form.
     */
    public function index(): Response
    {
        $searchDefaults = request()->only([
            'origin',
            'destination',
            'date',
            'return_date',
            'adults',
            'children',
            'infants',
            'is_return',
            'cabin_class',
        ]);

        return Inertia::render('Tenant/Bookings/Search', [
            'bookings' => Booking::query()
                ->with(['customer:id,first_name,last_name,email', 'provider:id,airline_name'])
                ->when(request('status'), fn (Builder $query, string $status) => $query->where('status', $status))
                ->when(request('pnr'), fn (Builder $query, string $pnr) => $query->where('pnr', 'like', "%{$pnr}%"))
                ->when(request('customer'), function (Builder $query, string $customer): void {
                    $query->whereHas('customer', function (Builder $customerQuery) use ($customer): void {
                        $customerQuery
                            ->where('email', 'like', "%{$customer}%")
                            ->orWhere('first_name', 'like', "%{$customer}%")
                            ->orWhere('last_name', 'like', "%{$customer}%");
                    });
                })
                ->when(request('airline'), function (Builder $query, string $airline): void {
                    $query->whereHas('provider', function (Builder $providerQuery) use ($airline): void {
                        $providerQuery->where('airline_code', $airline);
                    });
                })
                ->latest()
                ->limit(25)
                ->get(),
            'filters' => request()->only(['status', 'pnr', 'customer', 'airline']),
            'airlines' => TenantProvider::query()
                ->select('airline_code', 'airline_name')
                ->distinct()
                ->orderBy('airline_name')
                ->get(),
            'searchDefaults' => [
                'origin' => strtoupper((string) ($searchDefaults['origin'] ?? '')),
                'destination' => strtoupper((string) ($searchDefaults['destination'] ?? '')),
                'date' => (string) ($searchDefaults['date'] ?? ''),
                'return_date' => (string) ($searchDefaults['return_date'] ?? ''),
                'adults' => (int) ($searchDefaults['adults'] ?? 1),
                'children' => (int) ($searchDefaults['children'] ?? 0),
                'infants' => (int) ($searchDefaults['infants'] ?? 0),
                'is_return' => filter_var($searchDefaults['is_return'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'cabin_class' => (string) ($searchDefaults['cabin_class'] ?? 'economy'),
            ],
            'searchDisplayMode' => tenant()->getInternal('search_display_mode') ?? 'per_offer',
        ]);
    }

    /**
     * Handle the flight search POST request.
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'origin' => 'required|string|size:3',
            'destination' => 'required|string|size:3',
            'date' => 'required|date_format:Y-m-d',
            'return_date' => 'nullable|required_if:is_return,true|date_format:Y-m-d|after_or_equal:date',
            'adults' => 'required|integer|min:1|max:9',
            'children' => 'nullable|integer|min:0|max:9',
            'infants' => 'nullable|integer|min:0|max:9',
            'is_return' => 'nullable',
        ]);

        $validated['is_return'] = filter_var($request->input('is_return'), FILTER_VALIDATE_BOOLEAN);

        $searchUuid = (string) Str::uuid();

        // Cache search parameters for 30 minutes
        Cache::put("flight_search_{$searchUuid}", $validated, now()->addMinutes(30));

        return redirect()->route('flights.results', ['uuid' => $searchUuid]);
    }

    /**
     * Show the results shell page.
     */
    public function results(string $uuid, Request $request)
    {
        $searchParams = Cache::get("flight_search_{$uuid}");

        if (! $searchParams) {
            return redirect()->route('flights.index')->with('error', 'Search expired. Please search again.');
        }

        $providers = TenantProvider::where('is_active', '=', true)
            ->get(['id', 'airline_name', 'airline_code', 'account_name']);

        // Handle Inertia Partial Reload for 'flights'
        $flights = [];
        if ($request->has('provider_id')) {
            $providerId = $request->input('provider_id');
            try {
                $providerConfig = TenantProvider::findOrFail($providerId);
                $provider = ProviderFactory::make($providerConfig);

                $qty = $searchParams['adults'] + ($searchParams['children'] ?? 0);
                $params = array_merge($searchParams, ['qty' => $qty]);

                // 1. Authenticated Command Path
                $providerFlights = collect($provider->searchAvailability($params));
                $providerConfig->update(['last_used_at' => now()]);

                // 2. Web Scraper Path (Optional Discovery)
                if ($this->shouldUseVidecomScraper($providerConfig)) {
                    try {
                        $scraper = new \App\Services\Airline\Videcom\VidecomScraper(
                            $providerConfig->airline_code,
                            $providerConfig->credentials['base_url'] ?? null
                        );
                        $scrapedFlights = $scraper->search($params);

                        foreach ($scrapedFlights as $scraped) {
                            $isDuplicate = $providerFlights->contains(fn ($f) => $f->flight_number === $scraped->flight_number &&
                            $f->departure_time === $scraped->departure_time
                            );

                            if (! $isDuplicate) {
                                $providerFlights->push($scraped);
                            }
                        }
                    } catch (Exception $e) {
                        Log::warning("Scraper failed for provider {$providerConfig->id}: ".$e->getMessage());
                    }
                }
                $flights = $providerFlights->sortBy('pricing.total')->values()->toArray();
            } catch (Exception $e) {
                Log::error('Inertia partial flight fetch failed: '.$e->getMessage());

                // We let Inertia handle the error via the session or response
                return back()->withErrors(['provider_'.$providerId => $e->getMessage()]);
            }
        }

        return Inertia::render('Tenant/Bookings/SearchResults', [
            'uuid' => $uuid,
            'query' => $searchParams,
            'providers' => $providers,
            'flights' => $flights, // This will be empty on initial visit, populated on partial reload
            'searchDisplayMode' => tenant()->getInternal('search_display_mode') ?? 'per_offer',
        ]);
    }

    /**
     * Fetch flights for a specific provider (AJAX).
     */
    public function fetchFlights(Request $request)
    {
        $validated = $request->validate([
            'uuid' => 'required|string',
            'provider_id' => 'required|exists:tenant_providers,id',
        ]);

        $searchParams = Cache::get("flight_search_{$validated['uuid']}");

        if (! $searchParams) {
            return response()->json(['error' => 'Search session expired'], 410);
        }

        try {
            $providerConfig = TenantProvider::findOrFail($validated['provider_id']);
            $provider = ProviderFactory::make($providerConfig);
            $providerConfig->update(['last_used_at' => now()]);

            $qty = $searchParams['adults'] + ($searchParams['children'] ?? 0);

            // Merge search params for the service
            $params = array_merge($searchParams, ['qty' => $qty]);

            // 1. Authenticated Command Path
            $flights = collect($provider->searchAvailability($params));

            // 2. Web Scraper Path (Optional Discovery)
            if ($this->shouldUseVidecomScraper($providerConfig)) {
                try {
                    $scraper = new \App\Services\Airline\Videcom\VidecomScraper(
                        $providerConfig->airline_code,
                        $providerConfig->credentials['base_url'] ?? null
                    );
                    $scrapedFlights = $scraper->search($params);

                    foreach ($scrapedFlights as $scraped) {
                        $isDuplicate = $flights->contains(fn ($f) => $f->flight_number === $scraped->flight_number &&
                        $f->departure_time === $scraped->departure_time
                        );

                        if (! $isDuplicate) {
                            $flights->push($scraped);
                        }
                    }
                } catch (Exception $e) {
                    Log::warning("Scraper failed for provider {$providerConfig->id}: ".$e->getMessage());
                }
            }

            return response()->json([
                'provider_id' => $validated['provider_id'],
                'flights' => $flights->sortBy('pricing.total')->values(),
            ]);
        } catch (Exception $e) {
            Log::error('Async flight fetch failed: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Fetch seat map for a specific flight (AJAX).
     */
    public function seatmap(Request $request)
    {
        $validated = $request->validate([
            'provider_id' => 'required|exists:tenant_providers,id',
            'flight_number' => 'required|string',
            'date' => 'required|date',
        ]);

        try {
            $providerConfig = TenantProvider::findOrFail($validated['provider_id']);
            $provider = ProviderFactory::make($providerConfig);

            $seatMap = $provider->getSeatMap($validated['flight_number'], $validated['date']);

            return response()->json($seatMap);
        } catch (Exception $e) {
            Log::error('Async seat map fetch failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to fetch seat map: '.$e->getMessage()], 422);
        }
    }

    /**
     * Show the passenger info entry page for a selected flight.
     */
    public function select(Request $request): Response
    {
        $validated = $request->validate([
            'uuid' => 'required|string',
            'provider_id' => 'required|exists:tenant_providers,id',
            'flight' => 'required|array',
            'reservation_type' => 'required|in:QQ,NN',
        ]);

        $searchParams = Cache::get("flight_search_{$validated['uuid']}");
        $providerConfig = TenantProvider::findOrFail($validated['provider_id']);
        $provider = ProviderFactory::make($providerConfig);
        $passportRequired = $this->isInternationalFlight($validated['flight']);

        Log::info('Booking payload snapshot', [
            'provider_id' => $validated['provider_id'],
            'reservation_type' => $validated['reservation_type'],
            'passenger_count' => count($validated['passengers'] ?? []),
            'passengers' => collect($validated['passengers'] ?? [])->map(function (array $passenger): array {
                return [
                    'type' => $passenger['type'] ?? null,
                    'first_name' => $passenger['first_name'] ?? null,
                    'last_name' => $passenger['last_name'] ?? null,
                    'gender' => $passenger['gender'] ?? null,
                    'dob' => $passenger['dob'] ?? null,
                ];
            })->values()->all(),
            'contact' => [
                'first_name' => $validated['customer']['first_name'] ?? null,
                'last_name' => $validated['customer']['last_name'] ?? null,
                'email' => $validated['customer']['email'] ?? null,
                'phone' => $validated['customer']['phone'] ?? null,
            ],
        ]);

        return Inertia::render('Tenant/Bookings/PassengerInfo', [
            'uuid' => $validated['uuid'],
            'provider_id' => $validated['provider_id'],
            'flight' => $validated['flight'],
            'reservation_type' => $validated['reservation_type'],
            'passportRequired' => $passportRequired,
            'searchParams' => $searchParams,
            'ancillaryCatalog' => $provider->getAncillaryCatalog($validated['flight'], $searchParams ?? []),
        ]);
    }

    /**
     * Handle the booking submission.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'uuid' => 'required|string',
            'provider_id' => 'required|exists:tenant_providers,id',
            'flight' => 'required|array',
            'reservation_type' => 'required|in:QQ,NN',
            'passengers' => 'required|array|min:1',
            'passengers.*.type' => 'required|in:adult,child,infant',
            'passengers.*.first_name' => 'required|string|alpha:ascii',
            'passengers.*.last_name' => 'required|string|alpha:ascii',
            'passengers.*.dob' => 'nullable|date',
            'passengers.*.gender' => 'required|in:M,F',
            'passengers.*.passport_number' => 'nullable|string',
            'passengers.*.passport_expiry' => 'nullable|date',
            'passengers.*.passport_issue_country' => 'nullable|string|size:3',
            'passengers.*.nationality' => 'nullable|string|size:3',
            'customer.first_name' => 'required|string',
            'customer.last_name' => 'required|string',
            'customer.email' => 'required|email',
            'customer.phone' => 'nullable|string',
            'extras' => 'nullable|array',
            'extras.seats' => 'nullable|array',
            'extras.seats.*' => 'nullable|array',
            'extras.seats.*.*' => 'nullable|string|max:12',
            'extras.selected_services' => 'nullable|array',
            'extras.selected_services.*.code' => 'required|string',
            'extras.selected_services.*.quantity' => 'nullable|integer|min:0',
            'extras.selected_services.*.passengers' => 'nullable|array',
            'extras.selected_services.*.passengers.*' => 'integer|min:0',
        ], [
            'passengers.*.first_name.alpha' => 'Passenger first name must contain letters only.',
            'passengers.*.last_name.alpha' => 'Passenger last name must contain letters only.',
        ]);

        $passportRequired = $this->isInternationalFlight($validated['flight']);

        $passportErrors = [];

        foreach ($validated['passengers'] as $index => $passenger) {
            $hasAnyPassportDetail = $this->passengerHasAnyPassportDetail($passenger);
            $mustProvidePassportDetails = $passportRequired || $hasAnyPassportDetail;

            if (! $mustProvidePassportDetails) {
                continue;
            }

            $requiredFields = [
                'passport_number' => $passportRequired
                    ? 'Passport number is required for international flights.'
                    : 'Complete all passport fields or clear all passport fields.',
                'passport_expiry' => $passportRequired
                    ? 'Passport expiry is required for international flights.'
                    : 'Complete all passport fields or clear all passport fields.',
                'passport_issue_country' => $passportRequired
                    ? 'Passport issue country is required for international flights.'
                    : 'Complete all passport fields or clear all passport fields.',
                'nationality' => $passportRequired
                    ? 'Nationality is required for international flights.'
                    : 'Complete all passport fields or clear all passport fields.',
            ];

            foreach ($requiredFields as $field => $message) {
                if (empty($passenger[$field])) {
                    $passportErrors["passengers.{$index}.{$field}"] = $message;
                }
            }
        }

        if ($passportErrors !== []) {
            return back()->withErrors($passportErrors)->withInput();
        }

        $searchParams = Cache::get("flight_search_{$validated['uuid']}") ?? [];

        // Create Customer
        $customer = \App\Models\Tenant\Customer::firstOrCreate(
            ['email' => $validated['customer']['email']],
            $validated['customer']
        );

        // Setup Provider
        $providerConfig = \App\Models\TenantProvider::findOrFail($validated['provider_id']);
        $provider = \App\Services\Airline\ProviderFactory::make($providerConfig);
        $providerConfig->update(['last_used_at' => now()]);

        // Map Itinerary for Service
        $itinerary = $validated['flight']['segments'] ?? [$validated['flight']];
        $mappedItinerary = array_map(function ($seg) {
            return [
                'flt_no' => $seg['flight_number'] ?? '000',
                'class' => $seg['class'] ?? 'Y',
                'date' => $seg['departure_time'] ?? now(),
                'origin' => $seg['departure_airport'] ?? 'XXX',
                'dest' => $seg['arrival_airport'] ?? 'XXX',
            ];
        }, $itinerary);

        $ancillaryCatalog = $provider->getAncillaryCatalog($validated['flight'], $searchParams);
        $ancillarySummary = VidecomAncillaryCatalog::selectedTotals(
            $ancillaryCatalog,
            $validated['extras']['selected_services'] ?? [],
            count($validated['passengers']),
            count($mappedItinerary)
        );

        // Map request payload
        $extras = $validated['extras'] ?? [];
        $extras['include_docs'] = $passportRequired || $this->passengersContainPassportDetails($validated['passengers']);

        $params = [
            'passengers' => $validated['passengers'],
            'contact' => $validated['customer'],
            'itinerary' => $mappedItinerary,
            'extras' => $extras,
            'reservation_type' => $validated['reservation_type'],
        ];

        // Ensure total price and currency
        $pricing = $validated['flight']['pricing'] ?? [];
        $baseTotal = is_array($pricing) && array_is_list($pricing)
            ? (float) collect($pricing)->sum(fn (array $price): float => (float) ($price['total'] ?? 0))
            : (float) ($pricing['total'] ?? 0);
        $totalPrice = $baseTotal + (float) ($ancillarySummary['total'] ?? 0);
        $currency = is_array($pricing) && array_is_list($pricing)
            ? (string) ($pricing[0]['currency'] ?? 'USD')
            : (string) ($pricing['currency'] ?? 'USD');

        $pnr = 'PENDING';
        $fareResponse = null;

        try {
            $fareResponse = $provider->getPricing($mappedItinerary, $validated['passengers']);
            $this->ensureProviderResponseIsSuccessful($fareResponse, 'pricing');

            $previewIssueCommand = filter_var($request->input('preview_issue_command', false), FILTER_VALIDATE_BOOLEAN);
            if ($previewIssueCommand && (method_exists($provider, 'previewBookingCommand') || is_callable([$provider, 'previewBookingCommand']))) {
                $issueCommand = $provider->previewBookingCommand($params);

                Log::info('Videcom preview issue command generated', [
                    'provider_id' => $validated['provider_id'],
                    'airline_code' => $providerConfig->airline_code,
                    'command' => $issueCommand,
                ]);

                return back()
                    ->with('success', 'Issuance command preview generated. No API call was sent.')
                    ->with('issue_command_preview', $issueCommand);
            }

            // Call airline API to generate PNR
            $bookingResponse = $provider->createBooking($params);
            $this->ensureProviderResponseIsSuccessful($bookingResponse, 'booking');

            $pnr = $this->extractPnrLocator($bookingResponse) ?? 'PENDING';

            // Fallback for demo or unrecognized formats
            if ($pnr === 'PENDING') {
                throw new Exception('Airline did not return a valid PNR locator.');
            }
        } catch (\Exception $e) {
            Log::error('Flight booking generation failed: '.$e->getMessage());

            $isTimeout = str_contains(strtolower($e->getMessage()), 'timed out')
                || str_contains(strtolower($e->getMessage()), 'curl error 28');

            $message = $isTimeout
                ? 'Airline request timed out. Please review and confirm the booking again.'
                : 'Failed to communicate with the airline: '.$e->getMessage();

            return back()
                ->withInput()
                ->with('error', $message);
        }

        // Create Booking locally
        $booking = \App\Models\Tenant\Booking::create([
            'customer_id' => $customer->id,
            'pnr' => $pnr,
            'tenant_provider_id' => $validated['provider_id'],
            'status' => $validated['reservation_type'] === 'NN' ? 'confirmed' : 'pending',
            'total_price' => $totalPrice,
            'currency' => $currency,
            'created_by' => $request->user()?->id ?? 1,
            'raw_request' => [
                'validated' => $validated,
                'provider_payload' => $params,
                'ancillary_catalog' => $ancillaryCatalog,
                'ancillary_summary' => $ancillarySummary,
            ],
            'raw_response' => [
                'fare' => $fareResponse instanceof \SimpleXMLElement ? $fareResponse->asXML() : $fareResponse,
                'booking' => $bookingResponse instanceof \SimpleXMLElement ? $bookingResponse->asXML() : $bookingResponse,
            ],
        ]);

        // Create Passengers
        foreach ($validated['passengers'] as $pData) {
            $booking->passengers()->create($pData);
        }

        // Create Flight Segment (Dummy example based on flight array)
        if (isset($validated['flight']['segments'])) {
            foreach ($validated['flight']['segments'] as $segment) {
                $booking->flightSegments()->create([
                    'flight_number' => $segment['flight_number'] ?? 'UNKNOWN',
                    'origin_airport' => $segment['departure_airport'] ?? 'XXX',
                    'destination_airport' => $segment['arrival_airport'] ?? 'XXX',
                    'departure_time' => \Carbon\Carbon::parse($segment['departure_time']),
                    'arrival_time' => \Carbon\Carbon::parse($segment['arrival_time']),
                ]);
            }
        } else {
            // Fallback for flat structure
            $booking->flightSegments()->create([
                'flight_number' => $validated['flight']['flight_number'] ?? 'UNKNOWN',
                'origin_airport' => $validated['flight']['departure_airport'] ?? 'XXX',
                'destination_airport' => $validated['flight']['arrival_airport'] ?? 'XXX',
                'departure_time' => \Carbon\Carbon::parse($validated['flight']['departure_time'] ?? now()),
                'arrival_time' => \Carbon\Carbon::parse($validated['flight']['arrival_time'] ?? now()->addHours(2)),
            ]);
        }

        return redirect()->route('flights.show', $booking)->with('success', 'Booking created successfully!');
    }

    /**
     * Show the booking receipt.
     */
    public function show(\App\Models\Tenant\Booking $booking): Response
    {
        $booking->load(['customer', 'passengers', 'flightSegments', 'provider', 'createdBy', 'tickets']);

        return Inertia::render('Tenant/Bookings/Show', [
            'booking' => $booking,
        ]);
    }

    protected function ensureProviderResponseIsSuccessful(mixed $response, string $context): void
    {
        // If it's a string, it's likely an error (either HTML stripped or plain text error)
        if (is_string($response)) {
            $response = trim($response);

            // If it's a plain string that doesn't look like XML, treat it as an error
            if (! str_starts_with($response, '<')) {
                throw new Exception("Videcom {$context} error: {$response}");
            }
        }

        // Convert SimpleXMLElement back to string to check for the word ERROR
        $rawResponse = ($response instanceof \SimpleXMLElement) ? $response->asXML() : (string) $response;
        $normalizedResponse = Str::lower($rawResponse);

        if (Str::contains($normalizedResponse, [
            'error',
            'invalid entry',
            'not authorised',
            'not authorized',
            'exception',
            'failed',
        ])) {
            // Try to get a more readable error from XML if possible
            $errorMessage = $response instanceof \SimpleXMLElement ? (string) ($response->Error ?? $response->Message ?? $response) : trim($rawResponse);

            if (empty(trim($errorMessage))) {
                $errorMessage = trim($rawResponse);
            }

            throw new Exception("Videcom {$context} error: {$errorMessage}");
        }
    }

    protected function extractPnrLocator(mixed $bookingResponse): ?string
    {
        if ($bookingResponse instanceof \SimpleXMLElement) {
            $attributeLocator = strtoupper(trim((string) ($bookingResponse['RLOC'] ?? '')));
            if ($attributeLocator !== '') {
                return $attributeLocator;
            }

            $directLocator = strtoupper(trim((string) ($bookingResponse->Locator ?? $bookingResponse->RecordLocator ?? $bookingResponse->PNR ?? '')));
            if ($directLocator !== '') {
                return $directLocator;
            }

            $pnrAttribute = $bookingResponse->xpath('//PNR/@RLOC');
            if (is_array($pnrAttribute) && isset($pnrAttribute[0])) {
                $xpathLocator = strtoupper(trim((string) $pnrAttribute[0]));
                if ($xpathLocator !== '') {
                    return $xpathLocator;
                }
            }

            $xmlString = $bookingResponse->asXML() ?: '';

            return $this->extractPnrLocatorFromString($xmlString);
        }

        if (is_string($bookingResponse)) {
            return $this->extractPnrLocatorFromString($bookingResponse);
        }

        return null;
    }

    protected function extractPnrLocatorFromString(string $bookingResponse): ?string
    {
        if (preg_match('/\bRLOC="([A-Z0-9]{5,8})"/i', $bookingResponse, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/<Locator>([A-Z0-9]{5,8})<\/Locator>/i', $bookingResponse, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/<RecordLocator>([A-Z0-9]{5,8})<\/RecordLocator>/i', $bookingResponse, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    protected function isInternationalFlight(array $flight): bool
    {
        $segments = $flight['segments'] ?? [$flight];
        if (empty($segments)) {
            return false;
        }

        foreach ($segments as $segment) {
            $origin = strtoupper((string) ($segment['departure_airport'] ?? ''));
            $destination = strtoupper((string) ($segment['arrival_airport'] ?? ''));

            if (empty($origin) || empty($destination)) {
                continue;
            }

            $airportMap = Airport::query()
                ->whereIn('iata_code', [$origin, $destination])
                ->get()
                ->keyBy('iata_code');

            $originCountry = $this->resolveAirportCountry($airportMap->get($origin));
            $destinationCountry = $this->resolveAirportCountry($airportMap->get($destination));

            if ($originCountry !== null && $destinationCountry !== null && $originCountry !== $destinationCountry) {
                return true;
            }
        }

        return false;
    }

    protected function resolveAirportCountry(?Airport $airport): ?string
    {
        if (! $airport) {
            return null;
        }

        $country = null;

        if (method_exists($airport, 'getTranslation')) {
            $country = $airport->getTranslation('country', 'en', false);
        }

        if (! $country) {
            $rawCountry = $airport->getAttributes()['country'] ?? null;

            if (is_string($rawCountry) && str_starts_with($rawCountry, '{')) {
                $decoded = json_decode($rawCountry, true);
                $country = $decoded['en'] ?? reset($decoded) ?: null;
            } elseif (is_string($rawCountry)) {
                $country = $rawCountry;
            }
        }

        if (! is_string($country) || trim($country) === '') {
            return null;
        }

        return strtoupper(trim($country));
    }

    protected function shouldUseVidecomScraper(TenantProvider $providerConfig): bool
    {
        if ($providerConfig->provider_type !== 'videcom') {
            return false;
        }

        $useScraper = data_get($providerConfig->credentials ?? [], 'use_scraper', false);

        return filter_var($useScraper, FILTER_VALIDATE_BOOLEAN);
    }

    protected function passengerHasAnyPassportDetail(array $passenger): bool
    {
        foreach (['passport_number', 'passport_expiry', 'passport_issue_country', 'nationality'] as $field) {
            $value = $passenger[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return true;
            }

            if ($value !== null && ! is_string($value)) {
                return true;
            }
        }

        return false;
    }

    protected function passengersContainPassportDetails(array $passengers): bool
    {
        foreach ($passengers as $passenger) {
            if (is_array($passenger) && $this->passengerHasAnyPassportDetail($passenger)) {
                return true;
            }
        }

        return false;
    }
}
