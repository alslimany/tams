<?php

namespace App\Http\Controllers\Tenant;

use App\DTOs\Airline\RoundTripPriceRequest;
use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\RoundTripPriceManager;
use App\Services\Airline\Videcom\VidecomAncillaryCatalog;
use App\Services\GlobalCache\FlightScheduleCacheService;
use App\Services\GlobalCache\GlobalFlightCacheSettingsService;
use App\Services\GlobalCache\RouteAvailabilityService;
use App\Services\Orders\OrderNumberGenerator;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function __construct(
        protected OrderNumberGenerator $orderNumberGenerator,
        protected RoundTripPriceManager $roundTripPriceManager,
        protected RouteAvailabilityService $routeAvailabilityService,
        protected FlightScheduleCacheService $flightScheduleCacheService,
        protected GlobalFlightCacheSettingsService $globalFlightCacheSettingsService,
    ) {}

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

        $bookings = $this->queryBookingsFromOrders(request())->latest()->limit(25)->get();

        return Inertia::render('Tenant/Bookings/Search', [
            'bookings' => $bookings->map(fn (Order $order): array => $this->formatOrderForBookingList($order))->values(),
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
        Cache::put("flight_search_{$searchUuid}", $validated, now()->addMinutes(30));

        return redirect()->route('flights.results', ['uuid' => $searchUuid]);
    }

    public function results(string $uuid, Request $request)
    {
        $searchParams = Cache::get("flight_search_{$uuid}");

        if (! $searchParams) {
            return redirect()->route('flights.index')->with('error', 'Search expired. Please search again.');
        }

        $providers = TenantProvider::where('is_active', '=', true)
            ->get(['id', 'airline_name', 'airline_code', 'account_name']);

        $filteredProviders = $this->filterProvidersByRouteAvailability(
            $providers,
            (string) ($searchParams['origin'] ?? ''),
            (string) ($searchParams['destination'] ?? ''),
        );

        $flights = [];
        if ($request->has('provider_id')) {
            $providerId = $request->input('provider_id');
            try {
                $providerConfig = TenantProvider::findOrFail($providerId);
                $provider = ProviderFactory::make($providerConfig);

                $params = $this->buildOneWayAvailabilityParams($searchParams);

                $providerFlights = collect($provider->searchAvailability($params));
                $providerConfig->update(['last_used_at' => now()]);

                $this->recordRouteAvailability(
                    $providerConfig,
                    (string) ($params['origin'] ?? ''),
                    (string) ($params['destination'] ?? ''),
                    $providerFlights,
                );

                $this->cacheFlightPricesForRoute(
                    $providerConfig,
                    (string) ($params['origin'] ?? ''),
                    (string) ($params['destination'] ?? ''),
                    (string) ($params['date'] ?? ''),
                    $providerFlights,
                );

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

                return back()->withErrors(['provider_'.$providerId => $e->getMessage()]);
            }
        }

        return Inertia::render('Tenant/Bookings/SearchResults', [
            'uuid' => $uuid,
            'query' => $searchParams,
            'providers' => $filteredProviders,
            'flights' => $flights,
            'searchDisplayMode' => tenant()->getInternal('search_display_mode') ?? 'per_offer',
        ]);
    }

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

            $params = $this->buildOneWayAvailabilityParams($searchParams);

            $flights = collect($provider->searchAvailability($params));

            $this->recordRouteAvailability(
                $providerConfig,
                (string) ($params['origin'] ?? ''),
                (string) ($params['destination'] ?? ''),
                $flights,
            );

            $this->cacheFlightPricesForRoute(
                $providerConfig,
                (string) ($params['origin'] ?? ''),
                (string) ($params['destination'] ?? ''),
                (string) ($params['date'] ?? ''),
                $flights,
            );

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

    public function openReservationAvailability(Request $request)
    {
        $validated = $request->validate([
            'provider_id' => 'required|exists:tenant_providers,id',
            'flight' => 'required|array',
        ]);

        $providerConfig = TenantProvider::findOrFail($validated['provider_id']);
        $provider = ProviderFactory::make($providerConfig);

        $segment = $this->mapFlightToProviderSegment($validated['flight']);

        $allowed = is_object($provider) && is_callable([$provider, 'canBookOpenReservation'])
            ? (bool) call_user_func([$provider, 'canBookOpenReservation'], $segment)
            : false;

        return response()->json([
            'allowed' => $allowed,
        ]);
    }

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

            return response()->json($provider->getSeatMap($validated['flight_number'], $validated['date']));
        } catch (Exception $e) {
            Log::error('Async seat map fetch failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to fetch seat map: '.$e->getMessage()], 422);
        }
    }

    public function getReturnOptions(Request $request)
    {
        $validated = $request->validate([
            'uuid' => 'required|string',
            'outbound_provider_id' => 'required|exists:tenant_providers,id',
            'outbound_flight' => 'required|array',
            'reservation_type' => 'nullable|in:QQ,NN',
            'return_date' => 'nullable|date_format:Y-m-d',
            'force_refresh' => 'nullable|boolean',
        ]);

        $searchParams = Cache::get("flight_search_{$validated['uuid']}");
        if (! $searchParams || ! filter_var($searchParams['is_return'] ?? false, FILTER_VALIDATE_BOOLEAN) || empty($searchParams['return_date'])) {
            return response()->json([
                'return_options' => [],
                'error' => 'Return date not found for this search session.',
            ], 422);
        }

        $outboundFlight = $validated['outbound_flight'];
        $outboundProviderId = (int) $validated['outbound_provider_id'];

        $effectiveReturnDate = (string) ($validated['return_date'] ?? $searchParams['return_date']);

        $returnSearchParams = array_merge($this->buildOneWayAvailabilityParams($searchParams), [
            'origin' => (string) ($outboundFlight['arrival_airport'] ?? ''),
            'destination' => (string) ($outboundFlight['departure_airport'] ?? ''),
            'date' => $effectiveReturnDate,
            'force_refresh' => filter_var($validated['force_refresh'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]);

        $providers = TenantProvider::query()
            ->where('is_active', '=', true)
            ->where('provider_type', '=', 'videcom')
            ->get(['id', 'airline_name', 'airline_code', 'account_name', 'provider_type', 'credentials']);

        $returnOptions = [];

        foreach ($providers as $providerConfig) {
            try {
                $provider = ProviderFactory::make($providerConfig);
                $returnFlights = collect($provider->searchReturnLeg($returnSearchParams));

                $this->recordRouteAvailability(
                    $providerConfig,
                    (string) ($returnSearchParams['origin'] ?? ''),
                    (string) ($returnSearchParams['destination'] ?? ''),
                    $returnFlights,
                );

                $this->cacheFlightPricesForRoute(
                    $providerConfig,
                    (string) ($returnSearchParams['origin'] ?? ''),
                    (string) ($returnSearchParams['destination'] ?? ''),
                    (string) ($returnSearchParams['date'] ?? ''),
                    $returnFlights,
                );

                $outboundTotal = (float) data_get($outboundFlight, 'pricing.total', 0);
                $passengerCounts = [
                    'adults' => (int) ($searchParams['adults'] ?? 1),
                    'children' => (int) ($searchParams['children'] ?? 0),
                    'infants' => (int) ($searchParams['infants'] ?? 0),
                ];

                foreach ($returnFlights as $returnFlight) {
                    $returnFlightData = is_array($returnFlight) ? $returnFlight : (array) $returnFlight;

                    if ((int) $providerConfig->id === $outboundProviderId) {
                        try {
                            $pricing = $this->roundTripPriceManager->priceWithCaching(
                                $provider,
                                $providerConfig->airline_code,
                                new RoundTripPriceRequest(
                                    outboundSegment: $this->mapFlightToRoundTripSegment($outboundFlight),
                                    returnSegment: $this->mapFlightToRoundTripSegment($returnFlightData),
                                    passengers: $passengerCounts,
                                    outboundPrice: $outboundTotal,
                                )
                            );

                            data_set($returnFlightData, 'pricing.total', $pricing->returnLegPrice);
                            data_set($returnFlightData, 'pricing.currency', $pricing->currency);
                            data_set($returnFlightData, 'pricing_method', 'roundtrip');
                            data_set($returnFlightData, 'pricing_total_roundtrip', $pricing->totalPrice);
                        } catch (\Throwable $pricingException) {
                            report($pricingException);
                            data_set($returnFlightData, 'pricing_method', 'oneway');
                        }
                    } else {
                        data_set($returnFlightData, 'pricing_method', 'oneway');
                    }

                    data_set($returnFlightData, 'provider_id', $providerConfig->id);
                    $returnOptions[] = $returnFlightData;
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        usort($returnOptions, static function (array $left, array $right): int {
            return ((float) data_get($left, 'pricing.total', 0)) <=> ((float) data_get($right, 'pricing.total', 0));
        });

        return response()->json([
            'return_options' => $returnOptions,
            'outbound_provider_id' => $outboundProviderId,
            'return_date' => $effectiveReturnDate,
        ]);
    }

    public function calendarHints(Request $request)
    {
        $validated = $request->validate([
            'origin' => 'required|string|size:3',
            'destination' => 'required|string|size:3',
            'month' => 'required|date_format:Y-m',
        ]);

        $start = Carbon::createFromFormat('Y-m-d', $validated['month'].'-01')->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $hints = [];
        $cursor = (clone $start);

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();
            $hints[$date] = $this->flightScheduleCacheService->getLowestPrice(
                (string) $validated['origin'],
                (string) $validated['destination'],
                $date,
            );

            $cursor->addDay();
        }

        return response()->json([
            'origin' => strtoupper((string) $validated['origin']),
            'destination' => strtoupper((string) $validated['destination']),
            'month' => (string) $validated['month'],
            'hints' => $hints,
        ]);
    }

    public function select(Request $request): Response
    {
        $validated = $request->validate([
            'uuid' => 'required|string',
            'provider_id' => 'required|exists:tenant_providers,id',
            'flight' => 'required|array',
            'reservation_type' => 'required|in:QQ,NN',
            'is_round_trip' => 'nullable|boolean',
            'outbound_provider_id' => 'nullable|exists:tenant_providers,id',
            'return_provider_id' => 'nullable|exists:tenant_providers,id',
        ]);

        $searchParams = Cache::get("flight_search_{$validated['uuid']}");
        $providerConfig = TenantProvider::findOrFail($validated['provider_id']);
        $provider = ProviderFactory::make($providerConfig);

        $isRoundTrip = filter_var($validated['is_round_trip'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $ancillaryCatalogByOffer = [];

        if ($isRoundTrip) {
            $outboundProviderId = (int) ($validated['outbound_provider_id'] ?? $validated['provider_id']);
            $returnProviderId = (int) ($validated['return_provider_id'] ?? $validated['provider_id']);

            $outboundProvider = $outboundProviderId === (int) $providerConfig->id
                ? $providerConfig
                : TenantProvider::find($outboundProviderId);
            $returnProvider = $returnProviderId === (int) $providerConfig->id
                ? $providerConfig
                : TenantProvider::find($returnProviderId);

            $outboundFlight = data_get($validated, 'flight.round_trip.outbound_flight', $validated['flight']);
            $returnFlight = data_get($validated, 'flight.round_trip.return_flight', $validated['flight']);

            if ($outboundProvider instanceof TenantProvider) {
                $outboundAirlineProvider = $outboundProvider->id === $providerConfig->id
                    ? $provider
                    : ProviderFactory::make($outboundProvider);
                $ancillaryCatalogByOffer['outbound'] = $outboundAirlineProvider->getAncillaryCatalog($outboundFlight, $searchParams ?? []);
            }

            if ($returnProvider instanceof TenantProvider) {
                $returnAirlineProvider = $returnProvider->id === $providerConfig->id
                    ? $provider
                    : ProviderFactory::make($returnProvider);
                $ancillaryCatalogByOffer['return'] = $returnAirlineProvider->getAncillaryCatalog($returnFlight, $searchParams ?? []);
            }
        }

        if ($ancillaryCatalogByOffer === []) {
            $ancillaryCatalogByOffer['oneway'] = $provider->getAncillaryCatalog($validated['flight'], $searchParams ?? []);
        }

        return Inertia::render('Tenant/Bookings/PassengerInfo', [
            'uuid' => $validated['uuid'],
            'provider_id' => $validated['provider_id'],
            'flight' => $validated['flight'],
            'reservation_type' => $validated['reservation_type'],
            'is_round_trip' => $isRoundTrip,
            'outbound_provider_id' => $validated['outbound_provider_id'] ?? null,
            'return_provider_id' => $validated['return_provider_id'] ?? null,
            'passportRequired' => $this->isInternationalFlight($validated['flight']),
            'searchParams' => $searchParams,
            'ancillaryCatalog' => $ancillaryCatalogByOffer['oneway'] ?? $provider->getAncillaryCatalog($validated['flight'], $searchParams ?? []),
            'ancillaryCatalogByOffer' => $ancillaryCatalogByOffer,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'uuid' => 'required|string',
            'provider_id' => 'required|exists:tenant_providers,id',
            'flight' => 'required|array',
            'reservation_type' => 'required|in:QQ,NN',
            'is_round_trip' => 'nullable|boolean',
            'outbound_provider_id' => 'nullable|exists:tenant_providers,id',
            'return_provider_id' => 'nullable|exists:tenant_providers,id',
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
            'extras.selected_services.*.offer_key' => 'nullable|string|in:oneway,outbound,return',
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

            foreach (['passport_number', 'passport_expiry', 'passport_issue_country', 'nationality'] as $field) {
                if (empty($passenger[$field])) {
                    $passportErrors["passengers.{$index}.{$field}"] = $passportRequired
                        ? 'Required for international flights.'
                        : 'Complete all passport fields or clear all passport fields.';
                }
            }
        }

        if ($passportErrors !== []) {
            return back()->withErrors($passportErrors)->withInput();
        }

        $searchParams = Cache::get("flight_search_{$validated['uuid']}") ?? [];
        $providerConfig = TenantProvider::findOrFail($validated['provider_id']);
        $provider = ProviderFactory::make($providerConfig);
        $providerConfig->update(['last_used_at' => now()]);

        $itinerary = $validated['flight']['segments'] ?? [$validated['flight']];
        $mappedItinerary = array_map(function ($segment): array {
            return [
                'flt_no' => $segment['flight_number'] ?? '000',
                'class' => $segment['class'] ?? 'Y',
                'date' => $segment['departure_time'] ?? now(),
                'origin' => $segment['departure_airport'] ?? 'XXX',
                'dest' => $segment['arrival_airport'] ?? 'XXX',
            ];
        }, $itinerary);

        $isRoundTrip = filter_var($validated['is_round_trip'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $catalogByOffer = [];
        $flightByOffer = [];

        if ($isRoundTrip) {
            $outboundProviderId = (int) ($validated['outbound_provider_id'] ?? $validated['provider_id']);
            $returnProviderId = (int) ($validated['return_provider_id'] ?? $validated['provider_id']);

            $outboundProvider = $outboundProviderId === (int) $providerConfig->id
                ? $providerConfig
                : TenantProvider::find($outboundProviderId);
            $returnProvider = $returnProviderId === (int) $providerConfig->id
                ? $providerConfig
                : TenantProvider::find($returnProviderId);

            $flightByOffer['outbound'] = data_get($validated, 'flight.round_trip.outbound_flight', $validated['flight']);
            $flightByOffer['return'] = data_get($validated, 'flight.round_trip.return_flight', $validated['flight']);

            if ($outboundProvider instanceof TenantProvider) {
                $outboundAirlineProvider = $outboundProvider->id === $providerConfig->id
                    ? $provider
                    : ProviderFactory::make($outboundProvider);
                $catalogByOffer['outbound'] = $outboundAirlineProvider->getAncillaryCatalog($flightByOffer['outbound'], $searchParams);
            }

            if ($returnProvider instanceof TenantProvider) {
                $returnAirlineProvider = $returnProvider->id === $providerConfig->id
                    ? $provider
                    : ProviderFactory::make($returnProvider);
                $catalogByOffer['return'] = $returnAirlineProvider->getAncillaryCatalog($flightByOffer['return'], $searchParams);
            }
        }

        if ($catalogByOffer === []) {
            $catalogByOffer['oneway'] = $provider->getAncillaryCatalog($validated['flight'], $searchParams);
            $flightByOffer['oneway'] = $validated['flight'];
        }

        $selectedServices = collect($validated['extras']['selected_services'] ?? []);
        $passengerCount = count($validated['passengers']);
        $ancillarySummary = [
            'lines' => [],
            'total' => 0.0,
        ];

        foreach ($catalogByOffer as $offerKey => $catalog) {
            $servicesForOffer = $selectedServices
                ->filter(function (array $selection) use ($offerKey, $catalogByOffer): bool {
                    if (isset($selection['offer_key'])) {
                        return $selection['offer_key'] === $offerKey;
                    }

                    if (count($catalogByOffer) === 1) {
                        return true;
                    }

                    return false;
                })
                ->map(fn (array $selection): array => [
                    'code' => $selection['code'] ?? null,
                    'quantity' => $selection['quantity'] ?? null,
                    'passengers' => $selection['passengers'] ?? [],
                ])
                ->values()
                ->all();

            if ($servicesForOffer === []) {
                continue;
            }

            $offerSegments = data_get($flightByOffer, "{$offerKey}.segments", [data_get($flightByOffer, $offerKey, [])]);

            $offerSummary = VidecomAncillaryCatalog::selectedTotals(
                $catalog,
                $servicesForOffer,
                $passengerCount,
                count($offerSegments)
            );

            $ancillarySummary['total'] += (float) ($offerSummary['total'] ?? 0);

            foreach ($offerSummary['lines'] ?? [] as $line) {
                $line['offer_key'] = $offerKey;
                $ancillarySummary['lines'][] = $line;
            }
        }

        $ancillaryCatalog = count($catalogByOffer) === 1
            ? reset($catalogByOffer)
            : $catalogByOffer;

        $extras = $validated['extras'] ?? [];

        if (isset($extras['selected_services']) && is_array($extras['selected_services'])) {
            $extras['selected_services'] = array_map(fn (array $selection): array => [
                'code' => $selection['code'] ?? null,
                'quantity' => $selection['quantity'] ?? null,
                'passengers' => $selection['passengers'] ?? [],
            ], $extras['selected_services']);
        }

        $extras['include_docs'] = $passportRequired || $this->passengersContainPassportDetails($validated['passengers']);

        $providerPayload = [
            'passengers' => $validated['passengers'],
            'contact' => $validated['customer'],
            'itinerary' => $mappedItinerary,
            'extras' => $extras,
            'reservation_type' => $validated['reservation_type'],
        ];

        $pricing = $validated['flight']['pricing'] ?? [];
        $baseTotal = is_array($pricing) && array_is_list($pricing)
            ? (float) collect($pricing)->sum(fn (array $price): float => (float) ($price['total'] ?? 0))
            : (float) ($pricing['total'] ?? 0);
        $totalPrice = $baseTotal + (float) ($ancillarySummary['total'] ?? 0);
        $currency = is_array($pricing) && array_is_list($pricing)
            ? (string) ($pricing[0]['currency'] ?? 'USD')
            : (string) ($pricing['currency'] ?? 'USD');

        try {
            $fareResponse = $provider->getPricing($mappedItinerary, $validated['passengers']);
            $this->ensureProviderResponseIsSuccessful($fareResponse, 'pricing');

            $previewIssueCommand = filter_var($request->input('preview_issue_command', false), FILTER_VALIDATE_BOOLEAN);
            if ($previewIssueCommand && method_exists($provider, 'previewBookingCommand')) {
                return back()
                    ->with('success', 'Issuance command preview generated. No API call was sent.')
                    ->with('issue_command_preview', $provider->previewBookingCommand($providerPayload));
            }

            $bookingResponse = $provider->createBooking($providerPayload);
            $this->ensureProviderResponseIsSuccessful($bookingResponse, 'booking');

            $pnr = $this->extractPnrLocator($bookingResponse);
            if (! $pnr) {
                throw new Exception('Airline did not return a valid PNR locator.');
            }
        } catch (\Throwable $exception) {
            report($exception);

            $isTimeout = str_contains(strtolower($exception->getMessage()), 'timed out')
                || str_contains(strtolower($exception->getMessage()), 'curl error 28');

            return back()
                ->withInput()
                ->with('error', $isTimeout
                    ? 'Airline request timed out. Please review and confirm the booking again.'
                    : 'Failed to communicate with the airline: '.$exception->getMessage());
        }

        $order = DB::transaction(function () use ($validated, $providerConfig, $totalPrice, $currency, $pnr, $providerPayload, $ancillaryCatalog, $ancillarySummary): Order {
            $order = Order::query()->create([
                'owner_type' => get_class(request()->user()),
                'owner_id' => request()->user()?->id,
                'number' => $this->orderNumberGenerator->generate(),
                'status' => 'confirmed',
                'issued_at' => now(),
                'subtotal' => $totalPrice,
                'tax_total' => 0,
                'grand_total' => $totalPrice,
                'amount_paid' => $totalPrice,
                'currency' => strtoupper($currency),
                'payment_method' => 'airline_token',
                'payment_reference' => $pnr,
                'contact' => $validated['customer'],
            ]);

            $order->items()->create([
                'type' => 'flight',
                'product_subtype' => filter_var($validated['is_round_trip'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'roundtrip' : 'oneway',
                'provider' => 'videcom',
                'provider_reference' => $pnr,
                'item_details' => [
                    'pnr' => $pnr,
                    'airline_code' => $providerConfig->airline_code,
                    'outbound_provider_id' => $validated['outbound_provider_id'] ?? $validated['provider_id'],
                    'return_provider_id' => $validated['return_provider_id'] ?? null,
                    'segments' => $validated['flight']['segments'] ?? [$validated['flight']],
                    'passengers' => $validated['passengers'],
                    'customer' => $validated['customer'],
                    'raw_request' => [
                        'provider_payload' => $providerPayload,
                        'ancillary_catalog' => $ancillaryCatalog,
                        'ancillary_summary' => $ancillarySummary,
                    ],
                ],
                'price' => $totalPrice,
                'taxes' => 0,
                'total' => $totalPrice,
                'currency' => strtoupper($currency),
                'status' => 'confirmed',
                'paid' => $totalPrice,
                'remaining' => 0,
            ]);

            return $order;
        });

        return redirect()->route('tickets.completed', ['booking' => $order->id])->with('success', 'Booking created successfully.');
    }

    public function show(Order $booking): Response
    {
        $booking->load(['items']);

        $firstItem = $booking->items->first();
        $providerCode = (string) data_get($firstItem?->item_details, 'airline_code', '');
        $provider = $providerCode === ''
            ? null
            : TenantProvider::query()->where('airline_code', $providerCode)->first(['id', 'airline_name', 'airline_code']);

        $segments = collect((array) data_get($firstItem?->item_details, 'segments', []))
            ->map(function (array $segment, int $index): array {
                return [
                    'id' => data_get($segment, 'id', $index + 1),
                    'flight_number' => (string) data_get($segment, 'flight_number', ''),
                    'origin_airport' => (string) data_get($segment, 'departure_airport', data_get($segment, 'origin', '')),
                    'destination_airport' => (string) data_get($segment, 'arrival_airport', data_get($segment, 'destination', '')),
                    'departure_time' => (string) data_get($segment, 'departure_time', data_get($segment, 'date', '')),
                    'arrival_time' => (string) data_get($segment, 'arrival_time', ''),
                ];
            })
            ->values();

        $passengers = collect((array) data_get($firstItem?->item_details, 'passengers', []))
            ->map(function (array $passenger, int $index): array {
                return [
                    'id' => data_get($passenger, 'id', $index + 1),
                    'first_name' => (string) data_get($passenger, 'first_name', ''),
                    'last_name' => (string) data_get($passenger, 'last_name', ''),
                    'type' => (string) data_get($passenger, 'type', 'adult'),
                    'gender' => strtoupper((string) data_get($passenger, 'gender', 'M')),
                ];
            })
            ->values();

        $tickets = $booking->items
            ->filter(fn (OrderItem $item): bool => filled($item->ticket_number))
            ->map(function (OrderItem $item): array {
                return [
                    'id' => $item->id,
                    'ticket_number' => $item->ticket_number,
                    'status' => $item->status,
                    'issued_at' => optional($item->updated_at)->toISOString(),
                ];
            })
            ->values();

        return Inertia::render('Tenant/Bookings/Show', [
            'booking' => [
                'id' => $booking->id,
                'pnr' => (string) ($firstItem?->provider_reference ?: $booking->payment_reference),
                'status' => $this->toBookingStatus($booking->status),
                'total_price' => (float) $booking->grand_total,
                'currency' => $booking->currency,
                'created_at' => optional($booking->created_at)->toISOString(),
                'provider' => $provider,
                'customer' => [
                    'first_name' => (string) data_get($booking->contact, 'first_name', ''),
                    'last_name' => (string) data_get($booking->contact, 'last_name', ''),
                    'email' => (string) data_get($booking->contact, 'email', ''),
                    'phone' => (string) data_get($booking->contact, 'phone', ''),
                ],
                'flight_segments' => $segments,
                'passengers' => $passengers,
                'tickets' => $tickets,
            ],
        ]);
    }

    protected function queryBookingsFromOrders(Request $request): Builder
    {
        return Order::query()
            ->with(['items:id,order_id,provider_reference,item_details,total,currency,status', 'owner'])
            ->when($request->string('status')->isNotEmpty(), fn (Builder $query): Builder => $query->where('status', $request->string('status')->toString()))
            ->when($request->string('pnr')->isNotEmpty(), function (Builder $query) use ($request): Builder {
                $pnr = $request->string('pnr')->toString();

                return $query->whereHas('items', fn (Builder $itemQuery): Builder => $itemQuery->where('provider_reference', 'like', "%{$pnr}%"));
            })
            ->when($request->string('customer')->isNotEmpty(), function (Builder $query) use ($request): Builder {
                $customer = $request->string('customer')->toString();

                return $query->where(function (Builder $nested) use ($customer): void {
                    $nested
                        ->where('contact->email', 'like', "%{$customer}%")
                        ->orWhere('contact->first_name', 'like', "%{$customer}%")
                        ->orWhere('contact->last_name', 'like', "%{$customer}%");
                });
            })
            ->when($request->string('airline')->isNotEmpty(), function (Builder $query) use ($request): Builder {
                $airline = $request->string('airline')->toString();

                return $query->whereHas('items', fn (Builder $itemQuery): Builder => $itemQuery->where('item_details->airline_code', $airline));
            });
    }

    protected function mapFlightToProviderSegment(array $flight): array
    {
        $segment = $flight['segments'][0] ?? $flight;

        return [
            'flt_no' => (string) ($segment['flight_number'] ?? $flight['flight_number'] ?? ''),
            'class' => (string) ($segment['class'] ?? data_get($flight, 'pricing.class_code', 'Y')),
            'date' => (string) ($segment['departure_time'] ?? $flight['departure_time'] ?? now()->toDateTimeString()),
            'origin' => (string) ($segment['departure_airport'] ?? $flight['departure_airport'] ?? ''),
            'dest' => (string) ($segment['arrival_airport'] ?? $flight['arrival_airport'] ?? ''),
            'departure_airport' => (string) ($segment['departure_airport'] ?? $flight['departure_airport'] ?? ''),
            'arrival_airport' => (string) ($segment['arrival_airport'] ?? $flight['arrival_airport'] ?? ''),
            'departure_time' => (string) ($segment['departure_time'] ?? $flight['departure_time'] ?? now()->toDateTimeString()),
        ];
    }

    protected function mapFlightToRoundTripSegment(array $flight): array
    {
        $segment = $flight['segments'][0] ?? $flight;

        return [
            'flight_number' => (string) ($segment['flight_number'] ?? ''),
            'class' => (string) ($segment['class'] ?? data_get($flight, 'pricing.class_code', 'Y')),
            'date' => (string) ($segment['departure_time'] ?? ''),
            'origin' => strtoupper((string) ($segment['departure_airport'] ?? '')),
            'destination' => strtoupper((string) ($segment['arrival_airport'] ?? '')),
        ];
    }

    protected function formatOrderForBookingList(Order $order): array
    {
        $firstItem = $order->items->first();
        $providerCode = (string) data_get($firstItem?->item_details, 'airline_code', '');
        $provider = $providerCode === ''
            ? null
            : TenantProvider::query()->where('airline_code', $providerCode)->first(['id', 'airline_name', 'airline_code']);

        return [
            'id' => $order->id,
            'pnr' => (string) ($firstItem?->provider_reference ?: $order->payment_reference),
            'status' => $this->toBookingStatus($order->status),
            'total_price' => (float) $order->grand_total,
            'currency' => $order->currency,
            'customer' => [
                'first_name' => (string) data_get($order->contact, 'first_name', data_get($order->owner, 'name', '')),
                'last_name' => (string) data_get($order->contact, 'last_name', ''),
                'email' => (string) data_get($order->contact, 'email', data_get($order->owner, 'email', '')),
            ],
            'provider' => $provider,
        ];
    }

    protected function toBookingStatus(string $orderStatus): string
    {
        return match (strtolower($orderStatus)) {
            'issued' => 'ticketed',
            'voided' => 'cancelled',
            default => strtolower($orderStatus),
        };
    }

    protected function ensureProviderResponseIsSuccessful(mixed $response, string $context): void
    {
        if (is_string($response)) {
            $response = trim($response);

            if (! str_starts_with($response, '<')) {
                throw new Exception("Videcom {$context} error: {$response}");
            }
        }

        $rawResponse = ($response instanceof \SimpleXMLElement) ? $response->asXML() : (string) $response;
        $normalizedResponse = Str::lower((string) $rawResponse);

        if (Str::contains($normalizedResponse, [
            'error',
            'invalid entry',
            'not authorised',
            'not authorized',
            'exception',
            'failed',
        ])) {
            throw new Exception("Videcom {$context} error: ".trim((string) $rawResponse));
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

            if ($origin === '' || $destination === '') {
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
                $country = $decoded['en'] ?? (is_array($decoded) ? reset($decoded) : null);
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

    /**
     * Normalize session search params to a one-way availability lookup.
     */
    protected function buildOneWayAvailabilityParams(array $searchParams): array
    {
        $params = array_merge($searchParams, [
            'qty' => ((int) ($searchParams['adults'] ?? 1)) + ((int) ($searchParams['children'] ?? 0)),
            'is_return' => false,
        ]);

        unset($params['return_date']);

        return $params;
    }

    protected function filterProvidersByRouteAvailability(Collection $providers, string $origin, string $destination): Collection
    {
        if (! $this->globalFlightCacheSettingsService->isRouteAvailabilityEnabled()) {
            return $providers;
        }

        $filtered = $providers->filter(function (TenantProvider $provider) use ($origin, $destination): bool {
            $availability = $this->routeAvailabilityService->hasFlights(
                (string) $provider->airline_code,
                $origin,
                $destination,
            );

            return $availability !== false;
        })->values();

        return $filtered->isNotEmpty() ? $filtered : $providers;
    }

    protected function recordRouteAvailability(TenantProvider $providerConfig, string $origin, string $destination, Collection $flights): void
    {
        if (! $this->globalFlightCacheSettingsService->isRouteAvailabilityEnabled()) {
            return;
        }

        $this->routeAvailabilityService->recordResult(
            (string) $providerConfig->airline_code,
            $origin,
            $destination,
            $flights->isNotEmpty(),
        );
    }

    protected function cacheFlightPricesForRoute(
        TenantProvider $providerConfig,
        string $origin,
        string $destination,
        string $date,
        Collection $flights,
    ): void {
        if (! $this->globalFlightCacheSettingsService->isScheduleCacheEnabled() || trim($date) === '') {
            return;
        }

        foreach ($flights as $flight) {
            $flightData = is_array($flight) ? $flight : (array) $flight;
            $segment = data_get($flightData, 'segments.0', []);
            $price = (float) data_get($flightData, 'pricing.total', 0);
            $currency = (string) data_get($flightData, 'pricing.currency', 'LYD');
            $bookingClass = (string) ($segment['class'] ?? data_get($flightData, 'pricing.class_code', ''));

            if ($price <= 0) {
                continue;
            }

            $this->flightScheduleCacheService->storePrice(
                airlineCode: (string) $providerConfig->airline_code,
                origin: $origin,
                destination: $destination,
                date: $date,
                bookingClass: $bookingClass !== '' ? $bookingClass : null,
                price: $price,
                currency: $currency,
                ttlHours: 24,
            );
        }
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
