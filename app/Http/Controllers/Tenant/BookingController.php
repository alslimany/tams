<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\ApplyFinancialSourceAndCommission;
use App\Actions\Finance\DetermineFinancialSource;
use App\Actions\Finance\InitializeTenantLedger;
use App\Actions\Finance\PostToLedger;
use App\Actions\Finance\ProcessProviderWalletTransactions;
use App\Actions\Finance\ProcessWalletTransactions;
use App\DTOs\Airline\RoundTripPriceRequest;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Concerns\ExtractsPnrLocator;
use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Models\Country;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\AgencyNetwork\ProviderSourceResolver;
use App\Services\AgencyNetwork\ProviderSourceSelector;
use App\Services\Airline\AgencyProviderResolver;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\RoundTripPriceManager;
use App\Services\Airline\Videcom\VidecomAncillaryCatalog;
use App\Services\GlobalCache\FlightScheduleCacheService;
use App\Services\GlobalCache\GlobalFlightCacheSettingsService;
use App\Services\GlobalCache\RouteAvailabilityService;
use App\Services\Orders\OrderNumberGenerator;
use Carbon\Carbon;
use Exception;
use g4t\IDScanner\Scanner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
    use ExtractsPnrLocator;

    public function __construct(
        protected OrderNumberGenerator $orderNumberGenerator,
        protected RoundTripPriceManager $roundTripPriceManager,
        protected RouteAvailabilityService $routeAvailabilityService,
        protected FlightScheduleCacheService $flightScheduleCacheService,
        protected GlobalFlightCacheSettingsService $globalFlightCacheSettingsService,
        protected AgencyProviderResolver $providerResolver,
        protected ProviderSourceResolver $providerSourceResolver,
        protected ProviderSourceSelector $providerSourceSelector,
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

        // Get airlines list based on agency settings
        $providers = $this->providerResolver->getAllActiveProviders();
        $airlines = $providers
            ->map(fn ($p) => [
                'airline_code' => $p->airline_code,
                'airline_name' => $p->airline_name,
            ])
            ->unique(fn ($a) => $a['airline_code'])
            ->sortBy('airline_name')
            ->values();

        return Inertia::render('Tenant/Bookings/Search', [
            'bookings' => $bookings->map(fn (Order $order): array => $this->formatOrderForBookingList($order))->values(),
            'filters' => request()->only(['status', 'pnr', 'customer', 'airline']),
            'airlines' => $airlines,
            'searchDefaults' => [
                'origin' => strtoupper((string) ($searchDefaults['origin'] ?? '')),
                'destination' => strtoupper((string) ($searchDefaults['destination'] ?? '')),
                'date' => (string) ($searchDefaults['date'] ?? ''),
                'return_date' => (string) ($searchDefaults['return_date'] ?? ''),
                'adults' => (int) ($searchDefaults['adults'] ?? 1),
                'children' => (int) ($searchDefaults['children'] ?? 0),
                'infants' => (int) ($searchDefaults['infants'] ?? 0),
                'is_return' => array_key_exists('is_return', $searchDefaults)
                    ? filter_var($searchDefaults['is_return'], FILTER_VALIDATE_BOOLEAN)
                    : true,
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

        // Get providers based on agency settings (uses default agency if forced)
        $providers = $this->providerResolver->getAllActiveProviders();

        $filteredProviders = $this->filterProvidersByRouteAvailability(
            $this->filterProvidersByAccountAirports($providers, (string) ($searchParams['origin'] ?? ''), (string) ($searchParams['destination'] ?? '')),
            (string) ($searchParams['origin'] ?? ''),
            (string) ($searchParams['destination'] ?? ''),
        );

        $flights = [];
        if ($request->has('provider_id')) {
            $providerId = $request->input('provider_id');
            try {
                // Try to find provider from the resolved providers collection
                $providerConfig = $providers->firstWhere('id', $providerId);

                if (! $providerConfig) {
                    return back()->with('error', 'Provider not found.');
                }

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

        $airportCodes = array_filter([
            $searchParams['origin'] ?? null,
            $searchParams['destination'] ?? null,
        ]);

        $airports = Airport::query()
            ->whereIn('iata_code', $airportCodes)
            ->get(['iata_code', 'name', 'city', 'country'])
            ->keyBy('iata_code')
            ->map(fn ($a) => [
                'iata' => $a->iata_code,
                'name' => $a->getTranslation('name', app()->getLocale(), true),
                'city' => $a->getTranslation('city', app()->getLocale(), true),
                'country' => $a->getTranslation('country', app()->getLocale(), true),
            ]);

        return Inertia::render('Tenant/Bookings/SearchResults', [
            'uuid' => $uuid,
            'query' => $searchParams,
            'providers' => $filteredProviders,
            'providerSources' => $this->providerSourcesFor($filteredProviders),
            'flights' => $flights,
            'searchDisplayMode' => tenant()->getInternal('search_display_mode') ?? 'per_offer',
            'showSoldoutClasses' => (bool) (tenant()->getInternal('show_soldout_classes') ?? true),
            'airports' => $airports,
        ]);
    }

    public function changeOffers(Request $request, string $booking, string $ticket): Response
    {
        $validated = $request->validate([
            'segment_line' => 'required|integer|min:1',
            'origin' => 'required|string|size:3',
            'destination' => 'required|string|size:3',
            'date' => 'required|date_format:Y-m-d',
        ]);

        $order = Order::findOrFail($booking);
        $item = $order->items()->findOrFail($ticket);

        $searchParams = [
            'origin' => strtoupper($validated['origin']),
            'destination' => strtoupper($validated['destination']),
            'date' => $validated['date'],
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'is_return' => false,
            'segment_line' => (int) $validated['segment_line'],
            'change_booking_id' => $order->id,
            'change_ticket_id' => $item->id,
        ];

        $searchUuid = (string) Str::uuid();
        Cache::put("flight_search_{$searchUuid}", $searchParams, now()->addMinutes(30));

        // Resolve the single provider that issued this ticket so we only
        // search through that provider (not all active providers).
        $ticketProvider = app(\App\Http\Controllers\Tenant\TicketController::class)
            ->resolveProviderForTicketActionPublic($item);

        if ($ticketProvider) {
            $providers = collect([$ticketProvider]);
        } else {
            $providers = $this->providerResolver->getAllActiveProviders();
        }

        $filteredProviders = $this->filterProvidersByRouteAvailability(
            $this->filterProvidersByAccountAirports($providers, $searchParams['origin'], $searchParams['destination']),
            $searchParams['origin'],
            $searchParams['destination'],
        );

        return Inertia::render('Tenant/Bookings/ChangeOffers', [
            'uuid' => $searchUuid,
            'query' => $searchParams,
            'providers' => $filteredProviders,
            'providerSources' => $this->providerSourcesFor($filteredProviders),
            'searchDisplayMode' => tenant()->getInternal('search_display_mode') ?? 'per_offer',
            'showSoldoutClasses' => (bool) (tenant()->getInternal('show_soldout_classes') ?? true),
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
            ],
            'item' => [
                'id' => $item->id,
                'provider_reference' => $item->provider_reference,
                'ticket_number' => $item->ticket_number,
            ],
            'segment' => [
                'line' => (int) $validated['segment_line'],
                'origin' => $searchParams['origin'],
                'destination' => $searchParams['destination'],
                'date' => $searchParams['date'],
            ],
        ]);
    }

    public function fetchFlights(Request $request)
    {
        $validated = $request->validate([
            'uuid' => 'required|string',
            'provider_id' => 'required|integer',
            'provider_selector' => 'nullable|string',
        ]);

        $searchParams = Cache::get("flight_search_{$validated['uuid']}") ?? [];

        if (! $searchParams) {
            return response()->json(['error' => 'Search session expired'], 410);
        }

        // Get providers based on agency settings
        $providers = $this->providerResolver->getAllActiveProviders();

        $providerConfig = $this->resolveSelectedProvider(
            (int) $validated['provider_id'],
            $validated['provider_selector'] ?? null,
        );

        if (! $providerConfig) {
            return response()->json(['error' => 'Provider not found.'], 422);
        }

        try {
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
            'provider_id' => 'required|integer',
            'provider_selector' => 'nullable|string',
            'flight' => 'required|array',
        ]);

        $providerConfig = $this->resolveSelectedProvider(
            (int) $validated['provider_id'],
            $validated['provider_selector'] ?? null,
        );

        if (! $providerConfig) {
            return response()->json(['error' => 'Provider not found.'], 422);
        }

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
            'provider_id' => 'required|integer',
            'provider_selector' => 'nullable|string',
            'flight_number' => 'required|string',
            'date' => 'required|date',
        ]);

        try {
            $providerConfig = $this->resolveSelectedProvider(
                (int) $validated['provider_id'],
                $validated['provider_selector'] ?? null,
            );

            if (! $providerConfig) {
                return response()->json(['error' => 'Provider not found.'], 422);
            }

            $provider = ProviderFactory::make($providerConfig);

            return response()->json($provider->getSeatMap($validated['flight_number'], $validated['date']));
        } catch (Exception $e) {
            Log::error('Async seat map fetch failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to fetch seat map: '.$e->getMessage()], 422);
        }
    }

    public function fareRules(Request $request)
    {
        $validated = $request->validate([
            'provider_id' => 'required|integer',
            'provider_selector' => 'nullable|string',
            'fare_id' => 'required|string|max:20',
        ]);

        $providerConfig = $this->resolveSelectedProvider(
            (int) $validated['provider_id'],
            $validated['provider_selector'] ?? null,
        );

        if (! $providerConfig) {
            return response()->json(['error' => 'Provider not found.'], 422);
        }

        try {
            $provider = ProviderFactory::make($providerConfig);
            $rules = $provider->getFareRules($validated['fare_id']);

            return response()->json(['rules' => $rules]);
        } catch (Exception $e) {
            Log::error('Fare rules fetch failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to fetch fare rules.'], 422);
        }
    }

    public function getReturnOptions(Request $request)
    {
        $validated = $request->validate([
            'uuid' => 'required|string',
            'outbound_provider_id' => 'required|integer',
            'provider_selector' => 'nullable|string',
            'outbound_flight' => 'required|array',
            'reservation_type' => 'nullable|in:QQ,NN',
            'return_date' => 'nullable|date_format:Y-m-d',
            'force_refresh' => 'nullable|boolean',
        ]);

        $searchParams = Cache::get("flight_search_{$validated['uuid']}");

        if (! is_array($searchParams)) {
            $searchParams = [];
        }
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

        $resolvedOutboundProvider = $this->resolveSelectedProvider(
            $outboundProviderId,
            $validated['provider_selector'] ?? null,
        );

        $providers = $this->providerResolver->getAllActiveProviders()
            ->filter(fn (TenantProvider $provider): bool => $provider->provider_type === 'videcom')
            ->values();

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

                    if ($this->isSameProviderForRoundTripPricing($providerConfig, $resolvedOutboundProvider, $outboundProviderId)) {
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

                    // Skip flights where pricing is still zero after all attempts.
                    // This happens when one-way pricing failed AND round-trip pricing also failed.
                    if ((float) data_get($returnFlightData, 'pricing.total', 0) <= 0) {
                        continue;
                    }

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

    public function select(Request $request)
    {
        $validated = $request->validate([
            'uuid' => 'required|string',
            'provider_id' => 'nullable|integer',
            'provider_selector' => 'nullable|string',
            'provider_source_type' => 'nullable|string',
            'source_agency_tenant_id' => 'nullable|string',
            'merchant_tenant_id' => 'nullable|string',
            'network_membership_id' => 'nullable|integer',
            'provider_allocation_id' => 'nullable|integer',
            'source_provider_model' => 'nullable|string',
            'source_provider_id' => 'nullable|integer',
            'flight' => 'nullable|array',
            'reservation_type' => 'nullable|in:QQ,NN',
            'return_reservation_type' => 'nullable|in:QQ,NN',
            'is_round_trip' => 'nullable|boolean',
            'outbound_provider_id' => 'nullable|integer',
            'return_provider_id' => 'nullable|integer',
            'provider_selector' => 'nullable|string',
            'provider_source_type' => 'nullable|string',
            'source_agency_tenant_id' => 'nullable|string',
            'merchant_tenant_id' => 'nullable|string',
            'network_membership_id' => 'nullable|integer',
            'provider_allocation_id' => 'nullable|integer',
            'source_provider_model' => 'nullable|string',
            'source_provider_id' => 'nullable|integer',
        ]);

        $searchParams = Cache::get("flight_search_{$validated['uuid']}");
        $cachedOffer = $searchParams['selected_offer'] ?? null;

        // Try to load from cached offer if not provided in request (page refresh)
        $providerId = $validated['provider_id'] ?? $cachedOffer['provider_id'] ?? null;
        $flight = $validated['flight'] ?? $cachedOffer['flight'] ?? null;
        $reservationType = $validated['reservation_type'] ?? $cachedOffer['reservation_type'] ?? null;
        $isRoundTripRequested = $validated['is_round_trip'] ?? $cachedOffer['is_round_trip'] ?? false;
        $outboundProviderId = $validated['outbound_provider_id'] ?? $cachedOffer['outbound_provider_id'] ?? null;
        $returnProviderId = $validated['return_provider_id'] ?? $cachedOffer['return_provider_id'] ?? null;
        $returnReservationType = $validated['return_reservation_type'] ?? $cachedOffer['return_reservation_type'] ?? $reservationType;
        $sourceMetadata = $this->selectedOfferSourceMetadata($validated, is_array($cachedOffer) ? $cachedOffer : []);
        $sourceMetadata = $this->selectedOfferSourceMetadata($validated, is_array($cachedOffer) ? $cachedOffer : []);

        if (! $providerId || ! $flight || ! $reservationType) {
            return redirect()->route('flights.index')->with('error', 'Session expired. Please search again.');
        }

        $providerConfig = $this->resolveSelectedProvider(
            (int) $providerId,
            $sourceMetadata['provider_selector'] ?? null,
        );

        if (! $providerConfig) {
            return back()->with('error', 'Provider not found. Please search again.');
        }

        $provider = ProviderFactory::make($providerConfig);

        $isRoundTrip = filter_var($isRoundTripRequested, FILTER_VALIDATE_BOOLEAN);
        $ancillaryCatalogByOffer = [];

        // Get all providers for round trip lookup
        $allProviders = $this->providerResolver->getAllActiveProviders();

        if ($isRoundTrip) {
            $outboundProviderId = (int) ($outboundProviderId ?? $providerId);
            $returnProviderId = (int) ($returnProviderId ?? $providerId);

            $outboundProvider = $allProviders->firstWhere('id', $outboundProviderId);
            $returnProvider = $allProviders->firstWhere('id', $returnProviderId);

            $outboundFlight = data_get($flight, 'round_trip.outbound_flight', $flight);
            $returnFlight = data_get($flight, 'round_trip.return_flight', $flight);

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
            $ancillaryCatalogByOffer['oneway'] = $provider->getAncillaryCatalog($flight, $searchParams ?? []);
        }

        // Save selected offer to cache for potential page refresh
        $selectedOffer = array_merge([
            'provider_id' => $providerId,
            'flight' => $flight,
            'reservation_type' => $reservationType,
            'return_reservation_type' => $returnReservationType,
            'is_round_trip' => $isRoundTrip,
            'outbound_provider_id' => $outboundProviderId,
            'return_provider_id' => $returnProviderId,
            'selected_at' => now()->toDateTimeString(),
        ], $sourceMetadata);
        Cache::put(
            "flight_search_{$validated['uuid']}",
            array_merge(is_array($searchParams) ? $searchParams : [], ['selected_offer' => $selectedOffer]),
            now()->addMinutes(60)
        );

        return redirect()->route('flights.passengers', ['uuid' => $validated['uuid']]);
    }

    public function passengers(string $uuid): Response|RedirectResponse
    {
        $searchParams = Cache::get("flight_search_{$uuid}");
        $cachedOffer = $searchParams['selected_offer'] ?? null;

        if (! $cachedOffer) {
            return redirect()->route('flights.index')->with('error', 'Session expired. Please search again.');
        }

        $providerId = $cachedOffer['provider_id'];
        $flight = $cachedOffer['flight'];
        $reservationType = $cachedOffer['reservation_type'];
        $returnReservationType = $cachedOffer['return_reservation_type'] ?? $reservationType;
        $isRoundTrip = filter_var($cachedOffer['is_round_trip'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $outboundProviderId = $cachedOffer['outbound_provider_id'] ?? null;
        $returnProviderId = $cachedOffer['return_provider_id'] ?? null;

        $providerConfig = $this->resolveSelectedProvider(
            (int) $providerId,
            $cachedOffer['provider_selector'] ?? null,
        );

        if (! $providerConfig) {
            return back()->with('error', 'Provider not found. Please search again.');
        }

        $provider = ProviderFactory::make($providerConfig);
        $searchParamsForCatalog = $searchParams ? array_diff_key($searchParams, array_flip(['selected_offer', 'passengers', 'customer', 'extras'])) : [];

        $ancillaryCatalogByOffer = [];

        if ($isRoundTrip) {
            $allProviders = $this->providerResolver->getAllActiveProviders();
            $outboundProvider = $allProviders->firstWhere('id', $outboundProviderId);
            $returnProvider = $allProviders->firstWhere('id', $returnProviderId);

            if ($outboundProvider instanceof TenantProvider && isset($flight['round_trip']['outbound_flight'])) {
                $outboundAirlineProvider = ProviderFactory::make($outboundProvider);
                $ancillaryCatalogByOffer['outbound'] = $outboundAirlineProvider->getAncillaryCatalog($flight['round_trip']['outbound_flight'], $searchParamsForCatalog);
            }

            if ($returnProvider instanceof TenantProvider && isset($flight['round_trip']['return_flight'])) {
                $returnAirlineProvider = ProviderFactory::make($returnProvider);
                $ancillaryCatalogByOffer['return'] = $returnAirlineProvider->getAncillaryCatalog($flight['round_trip']['return_flight'], $searchParamsForCatalog);
            }
        }

        if ($ancillaryCatalogByOffer === []) {
            $ancillaryCatalogByOffer['oneway'] = $provider->getAncillaryCatalog($flight, $searchParamsForCatalog);
        }

        $cachedPassengers = $searchParams['passengers'] ?? [];
        $cachedCustomer = $searchParams['customer'] ?? null;

        return Inertia::render('Tenant/Bookings/PassengerInfo', [
            'uuid' => $uuid,
            'provider_id' => $providerId,
            'flight' => $flight,
            'reservation_type' => $reservationType,
            'return_reservation_type' => $returnReservationType,
            'is_round_trip' => $isRoundTrip,
            'outbound_provider_id' => $outboundProviderId,
            'return_provider_id' => $returnProviderId,
            'passportRequired' => $this->isInternationalFlight($flight),
            'searchParams' => $searchParamsForCatalog,
            'ancillaryCatalog' => $ancillaryCatalogByOffer['oneway'] ?? [],
            'ancillaryCatalogByOffer' => $ancillaryCatalogByOffer,
            'cached_passengers' => $cachedPassengers,
            'cached_customer' => $cachedCustomer,
            'countries' => Country::orderBy('name_en')
                ->get(['alpha2', 'alpha3', 'name_en', 'name_ar', 'name_fr']),
            'airports_map' => $this->buildAirportsMap($flight),
        ]);
    }

    /**
     * Scan a passport image using the Regula Forensics OCR/MRZ API (via g4t/id-scanner)
     * and return the extracted fields mapped to the passenger form format.
     */
    public function scanPassport(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        try {
            /** @var array<string, mixed> $result */
            $result = Scanner::scan([$request->file('image')], 'files');
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        /**
         * Parse a date that may come as YYMMDD (MRZ) or YYYY-MM-DD.
         * For DOB: 2-digit year < 50 → 2000s, ≥ 50 → 1900s.
         * For expiry: always future, so < 50 → 2000s.
         */
        $parseDate = function (?string $d): string {
            if (! $d) {
                return '';
            }

            // Already ISO format
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return $d;
            }

            // YYMMDD (MRZ standard)
            if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $d, $m)) {
                $yy = (int) $m[1];
                $yyyy = $yy < 50 ? "20{$m[1]}" : "19{$m[1]}";

                return "{$yyyy}-{$m[2]}-{$m[3]}";
            }

            return '';
        };

        $sex = strtoupper(substr((string) ($result['sex'] ?? 'M'), 0, 1));

        // MRZ given_name often contains first + father + grandfather names separated by spaces.
        // Airlines only need the first given name in the name field.
        $givenName = trim((string) ($result['given_name'] ?? ''));
        $firstName = strtoupper(strtok($givenName, ' ') ?: $givenName);

        return response()->json([
            'first_name' => $firstName,
            'last_name' => strtoupper(trim((string) ($result['surname'] ?? ''))),
            'dob' => $parseDate($result['date_of_birth'] ?? null),
            'passport_expiry' => $parseDate($result['date_of_expiry'] ?? null),
            'passport_number' => strtoupper(trim((string) ($result['document_number'] ?? ''))),
            'passport_issue_country' => strtoupper(trim((string) ($result['issuing_state_code'] ?? ''))),
            'nationality' => strtoupper(trim((string) ($result['nationality_code'] ?? ''))),
            'gender' => $sex === 'F' ? 'F' : 'M',
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'uuid' => 'required|string',
            'provider_id' => 'nullable|integer',
            'provider_selector' => 'nullable|string',
            'provider_source_type' => 'nullable|string',
            'source_agency_tenant_id' => 'nullable|string',
            'merchant_tenant_id' => 'nullable|string',
            'network_membership_id' => 'nullable|integer',
            'provider_allocation_id' => 'nullable|integer',
            'source_provider_model' => 'nullable|string',
            'source_provider_id' => 'nullable|integer',
            'flight' => 'nullable|array',
            'reservation_type' => 'nullable|in:QQ,NN',
            'is_round_trip' => 'nullable|boolean',
            'outbound_provider_id' => 'nullable|integer',
            'return_provider_id' => 'nullable|integer',
            'passengers' => 'nullable|array|min:1',
            'passengers.*.type' => 'nullable|in:adult,child,infant',
            'passengers.*.first_name' => 'nullable|string|alpha:ascii',
            'passengers.*.last_name' => 'nullable|string|alpha:ascii',
            'passengers.*.dob' => 'nullable|date',
            'passengers.*.gender' => 'nullable|in:M,F',
            'passengers.*.passport_number' => 'nullable|string',
            'passengers.*.passport_expiry' => 'nullable|date',
            'passengers.*.passport_issue_country' => 'nullable|string|size:3',
            'passengers.*.nationality' => 'nullable|string|size:3',
            'customer.first_name' => 'nullable|string',
            'customer.last_name' => 'nullable|string',
            'customer.email' => 'nullable|email',
            'customer.phone' => 'nullable|string',
            'extras' => 'nullable|array',
            'extras.seats' => 'nullable|array',
            'extras.seats.*' => 'nullable|array',
            'extras.seats.*.*' => 'nullable|string|max:12',
            'extras.selected_services' => 'nullable|array',
            'extras.selected_services.*.offer_key' => 'nullable|string|in:oneway,outbound,return',
            'extras.selected_services.*.code' => 'nullable|string',
            'extras.selected_services.*.quantity' => 'nullable|integer|min:0',
            'extras.selected_services.*.passengers' => 'nullable|array',
            'extras.selected_services.*.passengers.*' => 'integer|min:0',
            'extras.esim_selection' => 'nullable|array',
            'extras.esim_selection.package_id' => 'nullable|string|max:255',
            'extras.esim_selection.name' => 'nullable|string|max:255',
            'extras.esim_selection.price' => 'nullable|numeric|min:0',
            'extras.esim_selection.currency' => 'nullable|string|max:10',
            'ticketing_mode' => 'nullable|in:final,draft',
        ], [
            'passengers.*.first_name.alpha' => 'Passenger first name must contain letters only.',
            'passengers.*.last_name.alpha' => 'Passenger last name must contain letters only.',
        ]);

        // Load from cache if not provided in request (page refresh or retry)
        $searchParams = Cache::get("flight_search_{$validated['uuid']}");
        $cachedOffer = $searchParams['selected_offer'] ?? null;
        $cachedPassengers = $searchParams['passengers'] ?? null;
        $cachedCustomer = $searchParams['customer'] ?? null;
        $cachedExtras = $searchParams['extras'] ?? null;

        // Use cached data if not provided in request
        $passengers = $validated['passengers'] ?? $cachedPassengers ?? null;
        $customer = $validated['customer'] ?? $cachedCustomer ?? null;
        $extras = $validated['extras'] ?? $cachedExtras ?? [];

        // Must have passengers to proceed (either from request or cache)
        if (empty($passengers) || empty($customer)) {
            return redirect()->route('flights.index')->with('error', 'Session expired. Please search again.');
        }

        // Load offer data from cache
        $providerId = $validated['provider_id'] ?? $cachedOffer['provider_id'] ?? null;
        $flight = $validated['flight'] ?? $cachedOffer['flight'] ?? null;
        $reservationType = $validated['reservation_type'] ?? $cachedOffer['reservation_type'] ?? null;
        $isRoundTrip = filter_var($validated['is_round_trip'] ?? $cachedOffer['is_round_trip'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $outboundProviderId = $validated['outbound_provider_id'] ?? $cachedOffer['outbound_provider_id'] ?? null;
        $returnProviderId = $validated['return_provider_id'] ?? $cachedOffer['return_provider_id'] ?? null;
        $returnReservationType = $validated['return_reservation_type'] ?? $cachedOffer['return_reservation_type'] ?? $reservationType;
        $sourceMetadata = $this->selectedOfferSourceMetadata($validated, is_array($cachedOffer) ? $cachedOffer : []);

        // Save passengers and extras to cache for potential retry
        Cache::put("flight_search_{$validated['uuid']}", array_merge(is_array($searchParams) ? $searchParams : [], [
            'selected_offer' => $cachedOffer,
            'passengers' => $passengers,
            'customer' => $customer,
            'extras' => $extras,
        ]), now()->addMinutes(60));

        if (! $providerId || ! $flight) {
            return back()->with('error', 'Flight data not found. Please search again.');
        }

        $passportRequired = $this->isInternationalFlight($flight);
        $passportErrors = [];

        foreach ($passengers as $index => $passenger) {
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

        $providerConfig = $this->resolveSelectedProvider(
            (int) $providerId,
            $sourceMetadata['provider_selector'] ?? null,
        );

        if (! $providerConfig) {
            return back()->withErrors(['error' => 'Provider not found.']);
        }

        $provider = ProviderFactory::make($providerConfig);
        $providerConfig->update(['last_used_at' => now()]);

        $itinerary = $flight['segments'] ?? [$flight];

        // For round trips, tag each segment with its own reservation_type so the
        // provider can issue outbound and return legs independently (NN vs QQ).
        if ($isRoundTrip && isset($flight['round_trip'])) {
            $outboundSegments = $flight['round_trip']['outbound_flight']['segments']
                ?? [$flight['round_trip']['outbound_flight']];
            $returnSegments = $flight['round_trip']['return_flight']['segments']
                ?? [$flight['round_trip']['return_flight']];

            $mappedItinerary = [];

            foreach ($outboundSegments as $segment) {
                $mappedItinerary[] = [
                    'flt_no' => $segment['flight_number'] ?? '000',
                    'class' => $segment['class'] ?? 'Y',
                    'date' => $segment['departure_time'] ?? now(),
                    'origin' => $segment['departure_airport'] ?? 'XXX',
                    'dest' => $segment['arrival_airport'] ?? 'XXX',
                    'reservation_type' => $reservationType,
                ];
            }

            foreach ($returnSegments as $segment) {
                $mappedItinerary[] = [
                    'flt_no' => $segment['flight_number'] ?? '000',
                    'class' => $segment['class'] ?? 'Y',
                    'date' => $segment['departure_time'] ?? now(),
                    'origin' => $segment['departure_airport'] ?? 'XXX',
                    'dest' => $segment['arrival_airport'] ?? 'XXX',
                    'reservation_type' => $returnReservationType,
                ];
            }
        } else {
            $mappedItinerary = array_map(function ($segment) use ($reservationType): array {
                return [
                    'flt_no' => $segment['flight_number'] ?? '000',
                    'class' => $segment['class'] ?? 'Y',
                    'date' => $segment['departure_time'] ?? now(),
                    'origin' => $segment['departure_airport'] ?? 'XXX',
                    'dest' => $segment['arrival_airport'] ?? 'XXX',
                    'reservation_type' => $reservationType,
                ];
            }, $itinerary);
        }

        $catalogByOffer = [];
        $flightByOffer = [];

        // Get all providers for round trip lookup
        $allProviders = $this->providerResolver->getAllActiveProviders();

        if ($isRoundTrip) {
            $outboundProviderId = (int) ($outboundProviderId ?? $providerId);
            $returnProviderId = (int) ($returnProviderId ?? $providerId);

            $outboundProvider = $allProviders->firstWhere('id', $outboundProviderId);
            $returnProvider = $allProviders->firstWhere('id', $returnProviderId);

            $flightByOffer['outbound'] = data_get($flight, 'round_trip.outbound_flight', $flight);
            $flightByOffer['return'] = data_get($flight, 'round_trip.return_flight', $flight);

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
            $catalogByOffer['oneway'] = $provider->getAncillaryCatalog($flight, $searchParams);
            $flightByOffer['oneway'] = $flight;
        }

        $selectedServices = collect($extras['selected_services'] ?? []);
        $passengerCount = count($passengers);
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

        $esimSelection = isset($extras['esim_selection']) && is_array($extras['esim_selection'])
            ? $extras['esim_selection']
            : null;

        unset($extras['esim_selection']);

        if (isset($extras['selected_services']) && is_array($extras['selected_services'])) {
            $extras['selected_services'] = array_map(fn (array $selection): array => [
                'code' => $selection['code'] ?? null,
                'quantity' => $selection['quantity'] ?? null,
                'passengers' => $selection['passengers'] ?? [],
            ], $extras['selected_services']);
        }

        $extras['include_docs'] = $passportRequired || $this->passengersContainPassportDetails($passengers);

        $isDraft = ($validated['ticketing_mode'] ?? 'final') === 'draft';

        $providerPayload = [
            'passengers' => $passengers,
            'contact' => $customer,
            'itinerary' => $mappedItinerary,
            'extras' => $extras,
            'reservation_type' => $reservationType,
            'ticketing_mode' => $isDraft ? 'draft' : 'final',
        ];

        $pricing = $flight['pricing'] ?? [];
        $baseTotal = is_array($pricing) && array_is_list($pricing)
            ? (float) collect($pricing)->sum(fn (array $price): float => (float) ($price['total'] ?? 0))
            : (float) ($pricing['total'] ?? 0);
        $totalPrice = $baseTotal + (float) ($ancillarySummary['total'] ?? 0);
        $currency = is_array($pricing) && array_is_list($pricing)
            ? (string) ($pricing[0]['currency'] ?? 'USD')
            : (string) ($pricing['currency'] ?? 'USD');

        $issuer = $request->user();
        if (! $issuer instanceof User) {
            return back()->with('error', 'An authenticated user is required to issue a booking.');
        }

        try {
            $source = app(DetermineFinancialSource::class)->execute((string) $providerConfig->airline_code, $currency);
            $providerSourceType = (string) ($sourceMetadata['provider_source_type'] ?? '');
            $requiresSourceProviderWallet = in_array($providerSourceType, ['default_agency', 'agency_network'], true);

            if (! $isDraft) {
                if ($source->usesMasterAgencySupply()) {
                    app(ProcessWalletTransactions::class)->assertCanIssueForAmounts([
                        strtoupper($currency) => $totalPrice,
                    ], $issuer);
                }

                if ($source->usesOwnCredentials() || $requiresSourceProviderWallet) {
                    app(ProcessProviderWalletTransactions::class)->assertCanWithdrawForSelector(
                        $sourceMetadata['provider_selector'] ?? null,
                        $providerConfig,
                        strtoupper($currency),
                        $totalPrice,
                    );
                }
            }
        } catch (InsufficientWalletBalanceException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        try {
            $fareResponse = $provider->getPricing($mappedItinerary, $passengers);
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

        $order = DB::transaction(function () use ($isDraft, $providerConfig, $totalPrice, $currency, $pnr, $providerPayload, $ancillaryCatalog, $ancillarySummary, $customer, $isRoundTrip, $outboundProviderId, $returnProviderId, $flight, $passengers, $baseTotal, $sourceMetadata, $esimSelection): Order {
            $order = Order::query()->create([
                'owner_type' => get_class(request()->user()),
                'owner_id' => request()->user()?->id,
                'number' => $this->orderNumberGenerator->generate(),
                'status' => $isDraft ? 'pending' : 'confirmed',
                'issued_at' => $isDraft ? null : now(),
                'subtotal' => $totalPrice,
                'tax_total' => 0,
                'grand_total' => $totalPrice,
                'amount_paid' => $isDraft ? 0 : $totalPrice,
                'currency' => strtoupper($currency),
                'payment_method' => 'airline_token',
                'payment_reference' => $pnr,
                'contact' => $customer,
            ]);

            $order->items()->create([
                'type' => 'flight',
                'product_type' => 'ticket',
                'product_subtype' => $isRoundTrip ? 'roundtrip' : 'oneway',
                'provider' => 'videcom',
                'provider_reference' => $pnr,
                'item_details' => array_merge([
                    'pnr' => $pnr,
                    'airline_code' => $providerConfig->airline_code,
                    'outbound_provider_id' => $outboundProviderId,
                    'return_provider_id' => $returnProviderId,
                    'segments' => $flight['segments'] ?? [$flight],
                    'passengers' => $passengers,
                    'customer' => $customer,
                    'esim_pending_selection' => $esimSelection,
                    'raw_request' => [
                        'provider_payload' => $providerPayload,
                        'ancillary_catalog' => $ancillaryCatalog,
                        'ancillary_summary' => $ancillarySummary,
                    ],
                ], $sourceMetadata),
                'product_details' => [
                    'segments' => $flight['segments'] ?? [$flight],
                    'currency' => strtoupper($currency),
                ],
                'net_fare' => $baseTotal,
                'price' => $totalPrice,
                'taxes' => [],
                'total_tax' => 0,
                'total' => $totalPrice,
                'total_amount' => $totalPrice,
                'currency' => strtoupper($currency),
                'status' => $isDraft ? 'pending' : 'confirmed',
                'transaction_type' => 'issue',
                'paid' => $isDraft ? 0 : $totalPrice,
                'remaining' => $isDraft ? $totalPrice : 0,
            ]);

            return $order;
        });

        if ($isDraft) {
            Cache::forget("flight_search_{$validated['uuid']}");

            return redirect()->route('tickets.completed', ['booking' => $order->id])->with('success', 'Draft booking created. Issue the ticket when ready.');
        }

        $this->applyFinancialSourceAndCommission($order);

        $financialSources = $order->items
            ->pluck('item_details.financial_source')
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->unique();

        $resolvedPaymentMethod = match (true) {
            $financialSources->contains('master_agency_supply') && $financialSources->count() === 1 => 'default_agency_supply',
            $financialSources->contains('master_agency_supply') && $financialSources->contains('own_credentials') => 'mixed_supply',
            $financialSources->contains('own_credentials') && $financialSources->count() === 1 => 'own_credentials',
            default => (string) ($order->payment_method ?? 'airline_token'),
        };

        $order->update(['payment_method' => $resolvedPaymentMethod]);
        $order->load('items');

        if ($financialSources->contains('master_agency_supply')) {
            app(ProcessWalletTransactions::class)->execute($order, $request->user());
        }

        if ($financialSources->contains('own_credentials') || $order->items->contains(fn (OrderItem $item): bool => $this->hasSelectedSourceProvider($item))) {
            app(ProcessProviderWalletTransactions::class)->execute($order);
        }

        try {
            app(InitializeTenantLedger::class)->execute((string) $order->currency);
            app(PostToLedger::class)->execute($order, includeOwnCredentials: true);
        } catch (\Throwable $exception) {
            report($exception);

            Log::warning('Booking issuance completed but ledger posting failed.', [
                'order_id' => $order->id,
                'error' => $exception->getMessage(),
            ]);
        }

        // Clear cached booking data after successful issuance
        Cache::forget("flight_search_{$validated['uuid']}");

        return redirect()->route('tickets.completed', ['booking' => $order->id])->with('success', 'Booking created successfully.');
    }

    protected function applyFinancialSourceAndCommission(Order $order): void
    {
        app(ApplyFinancialSourceAndCommission::class)->execute($order);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $cachedOffer
     * @return array<string, mixed>
     */
    protected function selectedOfferSourceMetadata(array $validated, array $cachedOffer): array
    {
        $metadata = [];

        foreach ([
            'provider_selector',
            'provider_source_type',
            'source_agency_tenant_id',
            'merchant_tenant_id',
            'network_membership_id',
            'provider_allocation_id',
            'source_provider_model',
            'source_provider_id',
        ] as $key) {
            $value = $validated[$key] ?? $cachedOffer[$key] ?? null;

            if ($value !== null && $value !== '') {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    protected function providerSourcesFor(Collection $providers): array
    {
        return $providers
            ->filter(fn ($provider): bool => $provider instanceof TenantProvider)
            ->mapWithKeys(fn (TenantProvider $provider): array => [
                $provider->id => data_get($provider, 'provider_source_metadata') ?: $this->providerSourceSelector->own($provider),
            ])
            ->all();
    }

    protected function resolveSelectedProvider(int $providerId, ?string $providerSelector = null): ?TenantProvider
    {
        if (is_string($providerSelector) && $providerSelector !== '') {
            $resolved = $this->providerSourceResolver->resolve($providerSelector);

            if (($resolved['provider'] ?? null) instanceof TenantProvider) {
                return $resolved['provider'];
            }
        }

        return $this->providerResolver->findProviderById($providerId);
    }

    protected function isSameProviderForRoundTripPricing(TenantProvider $candidate, ?TenantProvider $outboundProvider, int $outboundProviderId): bool
    {
        if (! $outboundProvider instanceof TenantProvider) {
            return (int) $candidate->id === $outboundProviderId;
        }

        $candidateSelector = data_get($candidate, 'provider_source_metadata.provider_selector');
        $outboundSelector = data_get($outboundProvider, 'provider_source_metadata.provider_selector');

        if (is_string($candidateSelector) && $candidateSelector !== '' && is_string($outboundSelector) && $outboundSelector !== '') {
            return $candidateSelector === $outboundSelector;
        }

        $candidateSourceType = data_get($candidate, 'provider_source_metadata.source_type');
        $outboundSourceType = data_get($outboundProvider, 'provider_source_metadata.source_type');
        $candidateAllocationId = data_get($candidate, 'provider_source_metadata.provider_allocation_id');
        $outboundAllocationId = data_get($outboundProvider, 'provider_source_metadata.provider_allocation_id');

        if ($candidateSourceType !== null && $outboundSourceType !== null && $candidateAllocationId !== null && $outboundAllocationId !== null) {
            return $candidateSourceType === $outboundSourceType && (string) $candidateAllocationId === (string) $outboundAllocationId;
        }

        return (int) $candidate->id === (int) $outboundProvider->id;
    }

    protected function hasSelectedSourceProvider(OrderItem $item): bool
    {
        $selector = data_get($item->item_details, 'provider_selector');
        $sourceType = (string) data_get($item->item_details, 'provider_source_type', '');

        return is_string($selector)
            && $selector !== ''
            && in_array($sourceType, [ProviderSourceSelector::SourceDefaultAgency, ProviderSourceSelector::SourceAgencyNetwork], true);
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

    /**
     * Build a map of IATA code → country names for all airports in the flight.
     * Used by the PassengerInfo page to display country names in the trip summary.
     *
     * @param  array<string, mixed>  $flight
     * @return array<string, array{name_en: string, name_ar: string, name_fr: string, city_en: string, city_ar: string, city_fr: string}>
     */
    protected function buildAirportsMap(array $flight): array
    {
        $allSegments = $flight['segments'] ?? [$flight];

        if ($isRoundTrip = isset($flight['round_trip'])) {
            $allSegments = array_merge(
                $flight['round_trip']['outbound_flight']['segments'] ?? [$flight['round_trip']['outbound_flight'] ?? []],
                $flight['round_trip']['return_flight']['segments'] ?? [$flight['round_trip']['return_flight'] ?? []],
            );
        }

        $iataCodes = collect($allSegments)
            ->flatMap(fn ($s) => [
                strtoupper((string) ($s['departure_airport'] ?? $s['origin'] ?? '')),
                strtoupper((string) ($s['arrival_airport'] ?? $s['destination'] ?? '')),
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($iataCodes)) {
            return [];
        }

        $airports = Airport::whereIn('iata_code', $iataCodes)->get()->keyBy('iata_code');

        $alpha2Codes = $airports->map(fn ($a) => $this->resolveAirportCountry($a))->filter()->unique()->values()->all();

        $countriesByAlpha2 = Country::whereIn('alpha2', array_map('strtolower', $alpha2Codes))
            ->get(['alpha2', 'name_en', 'name_ar', 'name_fr'])
            ->keyBy(fn ($c) => strtoupper($c->alpha2));

        $map = [];
        foreach ($iataCodes as $iata) {
            $alpha2 = $this->resolveAirportCountry($airports->get($iata));
            if (! $alpha2) {
                continue;
            }
            $country = $countriesByAlpha2->get($alpha2);
            if (! $country) {
                continue;
            }
            $map[$iata] = [
                'name_en' => $country->name_en,
                'name_ar' => $country->name_ar,
                'name_fr' => $country->name_fr,
                'city_en' => $airports->get($iata)?->getTranslation('city', 'en') ?? '',
                'city_ar' => $airports->get($iata)?->getTranslation('city', 'ar') ?? '',
                'city_fr' => $airports->get($iata)?->getTranslation('city', 'fr') ?? '',
            ];
        }

        return $map;
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

    /**
     * Filter providers by account-level airport restrictions.
     *
     * When a provider's credentials define an `airports` list, the origin takes
     * priority: only providers whose airport list contains the origin are kept.
     * If no provider covers the origin, fall back to providers that cover the
     * destination. Providers with no `airports` restriction are always included.
     */
    protected function filterProvidersByAccountAirports(Collection $providers, string $origin, string $destination): Collection
    {
        $origin = strtoupper($origin);
        $destination = strtoupper($destination);

        $unrestricted = $providers->filter(fn (TenantProvider $p): bool => empty($p->credentials['airports'] ?? []));
        $restricted = $providers->filter(fn (TenantProvider $p): bool => ! empty($p->credentials['airports'] ?? []));

        if ($restricted->isEmpty()) {
            return $providers;
        }

        $normalize = fn (array $airports): array => array_map('strtoupper', $airports);

        $byOrigin = $restricted->filter(fn (TenantProvider $p): bool => in_array($origin, $normalize($p->credentials['airports']), true));

        $matched = $byOrigin->isNotEmpty()
            ? $byOrigin
            : $restricted->filter(fn (TenantProvider $p): bool => in_array($destination, $normalize($p->credentials['airports']), true));

        return $unrestricted->concat($matched)->values();
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
