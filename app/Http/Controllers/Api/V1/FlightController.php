<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Finance\ApplyFinancialSourceAndCommission;
use App\Actions\Finance\DetermineFinancialSource;
use App\Actions\Finance\InitializeTenantLedger;
use App\Actions\Finance\PostToLedger;
use App\Actions\Finance\ProcessProviderWalletTransactions;
use App\Actions\Finance\ProcessWalletTransactions;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Api\Controller;
use App\Http\Controllers\Concerns\ExtractsPnrLocator;
use App\Models\Tenant\Order;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\AgencyNetwork\ProviderSourceResolver;
use App\Services\Airline\AgencyProviderResolver;
use App\Services\Airline\CabinClassFilter;
use App\Services\Airline\FlightBookingPricing;
use App\Services\Airline\FlightOfferPresenter;
use App\Services\Airline\ProviderFactory;
use App\Services\GlobalCache\GlobalFlightCacheSettingsService;
use App\Services\GlobalCache\RouteAvailabilityService;
use App\Services\Orders\OrderNumberGenerator;
use App\Support\FareRulesFormatter;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FlightController extends Controller
{
    use ExtractsPnrLocator;

    public function __construct(
        protected AgencyProviderResolver $providerResolver,
        protected ProviderSourceResolver $providerSourceResolver,
        protected RouteAvailabilityService $routeAvailabilityService,
        protected GlobalFlightCacheSettingsService $globalFlightCacheSettingsService,
        protected OrderNumberGenerator $orderNumberGenerator,
        protected FlightOfferPresenter $flightOfferPresenter,
        protected FlightBookingPricing $flightBookingPricing,
    ) {}

    /**
     * Search flights (one-way or round-trip).
     *
     * Returns a search UUID and list of available providers.
     * The client then calls GET /flights/results/{uuid}?provider_id=X to fetch offers.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin' => ['required', 'string', 'size:3'],
            'destination' => ['required', 'string', 'size:3'],
            'date' => ['required', 'date_format:Y-m-d'],
            'return_date' => ['nullable', 'required_if:is_return,true', 'date_format:Y-m-d', 'after_or_equal:date'],
            'adults' => ['required', 'integer', 'min:1', 'max:9'],
            'children' => ['nullable', 'integer', 'min:0', 'max:9'],
            'infants' => ['nullable', 'integer', 'min:0', 'max:9'],
            'is_return' => ['nullable', 'boolean'],
            'cabin_class' => ['nullable', 'string', 'in:all,Y,C,F,W,economy,premium_economy,business,first'],
        ]);

        $searchUuid = (string) Str::uuid();
        $validated['cabin_class'] = $validated['cabin_class'] ?? 'all';
        Cache::put("flight_search_{$searchUuid}", $validated, now()->addMinutes(30));

        $providers = $this->providerResolver->getAllActiveProviders();

        $filteredProviders = $this->filterProvidersByRouteAvailability(
            $providers,
            strtoupper((string) $validated['origin']),
            strtoupper((string) $validated['destination']),
        );

        $providerList = $filteredProviders->map(function ($provider) {
            return [
                'id' => $provider->id,
                'airline_code' => $provider->airline_code,
                'airline_name' => $provider->airline_name,
                'provider_type' => $provider->provider_type,
                'is_active' => (bool) $provider->is_active,
                'commission_own' => (float) ($provider->commission_own ?? 0),
            ];
        })->values();

        return $this->success([
            'uuid' => $searchUuid,
            'providers' => $providerList,
            'search_params' => $validated,
        ], 'Search created. Fetch results with /flights/results/{uuid}?provider_id=X');
    }

    /**
     * Fetch flight offers from a specific provider for a search session.
     */
    public function results(string $uuid, Request $request): JsonResponse
    {
        $request->validate([
            'provider_id' => ['required', 'integer'],
        ]);

        $searchParams = Cache::get("flight_search_{$uuid}");

        if (! $searchParams) {
            return $this->error('Search session expired. Please search again.', 410);
        }

        $providerId = (int) $request->input('provider_id');
        $lockedProviderId = (int) ($searchParams['locked_provider_id'] ?? 0);

        if ($lockedProviderId > 0 && $providerId !== $lockedProviderId) {
            return $this->error('This search session is locked to the issuing airline provider.', 422);
        }

        $providers = $this->providerResolver->getAllActiveProviders();
        $providerConfig = $providers->firstWhere('id', $providerId);

        if (! $providerConfig) {
            return $this->error('Provider not found.', 404);
        }

        try {
            $flights = $this->fetchProviderFlights($providerConfig, $searchParams);
        } catch (Exception $e) {
            Log::error('API flight fetch failed: '.$e->getMessage(), [
                'uuid' => $uuid,
                'provider_id' => $providerId,
                'exception' => $e,
            ]);

            return $this->error('Failed to fetch flights: '.$e->getMessage(), 422);
        }

        return $this->success([
            'uuid' => $uuid,
            'provider_id' => $providerId,
            'airline_code' => $providerConfig->airline_code,
            'airline_name' => $providerConfig->airline_name,
            'offers' => $this->flightOfferPresenter->presentMany($flights, forApi: true),
        ]);
    }

    /**
     * Fetch fare rules (fare notes) for a selected offer class.
     */
    public function fareRules(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'integer'],
            'fare_id' => ['required', 'string', 'max:20'],
        ]);

        $providerConfig = $this->resolveProvider((int) $validated['provider_id']);

        if (! $providerConfig) {
            return $this->error('Provider not found.', 404);
        }

        try {
            $provider = ProviderFactory::make($providerConfig);
            $rules = FareRulesFormatter::toPlainText($provider->getFareRules($validated['fare_id']));

            return $this->success([
                'fare_id' => $validated['fare_id'],
                'rules' => $rules,
            ]);
        } catch (Exception $e) {
            Log::error('API fare rules fetch failed: '.$e->getMessage(), [
                'provider_id' => $validated['provider_id'],
                'fare_id' => $validated['fare_id'],
            ]);

            return $this->error('Failed to fetch fare rules.', 422);
        }
    }

    /**
     * Select a flight offer for booking.
     *
     * Caches the selected offer in the search session so the mobile client
     * can proceed to enter passenger details and book.
     */
    public function select(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uuid' => ['required', 'string'],
            'provider_id' => ['required', 'integer'],
            'provider_selector' => ['nullable', 'string'],
            'flight' => ['required', 'array'],
            'reservation_type' => ['nullable', 'string', 'in:QQ,NN'],
            'is_round_trip' => ['nullable', 'boolean'],
            'outbound_provider_id' => ['nullable', 'integer'],
            'return_provider_id' => ['nullable', 'integer'],
        ]);

        $searchParams = Cache::get("flight_search_{$validated['uuid']}");

        if (! $searchParams) {
            return $this->error('Search session expired. Please search again.', 410);
        }

        $providerConfig = $this->resolveProvider((int) $validated['provider_id']);

        if (! $providerConfig) {
            return $this->error('Provider not found.', 404);
        }

        $selectedOffer = [
            'provider_id' => (int) $validated['provider_id'],
            'provider_selector' => $validated['provider_selector'] ?? null,
            'provider_name' => $providerConfig->airline_name,
            'provider_code' => $providerConfig->airline_code,
            'flight' => $validated['flight'],
            'reservation_type' => $validated['reservation_type'] ?? 'NN',
            'is_round_trip' => (bool) ($validated['is_round_trip'] ?? false),
            'outbound_provider_id' => $validated['outbound_provider_id'] ?? null,
            'return_provider_id' => $validated['return_provider_id'] ?? null,
            'selected_at' => now()->toDateTimeString(),
        ];

        Cache::put(
            "flight_search_{$validated['uuid']}",
            array_merge(is_array($searchParams) ? $searchParams : [], ['selected_offer' => $selectedOffer]),
            now()->addMinutes(60)
        );

        return $this->success($selectedOffer, 'Flight selected. Proceed to book with passengers.');
    }

    /**
     * Check whether open reservation (QQ) is available for a flight offer.
     */
    public function openReservationAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'integer'],
            'provider_selector' => ['nullable', 'string'],
            'flight' => ['required', 'array'],
        ]);

        $providerConfig = $this->resolveSelectedProvider(
            (int) $validated['provider_id'],
            $validated['provider_selector'] ?? null,
        );

        if (! $providerConfig) {
            return $this->error('Provider not found.', 404);
        }

        try {
            $provider = ProviderFactory::make($providerConfig);
            $segment = $this->flightBookingPricing->mapItinerary($validated['flight'], 'QQ')[0] ?? [];

            $allowed = is_object($provider) && is_callable([$provider, 'canBookOpenReservation'])
                ? (bool) call_user_func([$provider, 'canBookOpenReservation'], $segment)
                : false;

            return $this->success([
                'allowed' => $allowed,
            ]);
        } catch (Exception $e) {
            Log::error('API open reservation availability check failed: '.$e->getMessage(), [
                'provider_id' => $validated['provider_id'],
            ]);

            return $this->error('Failed to check open reservation availability: '.$e->getMessage(), 422);
        }
    }

    /**
     * Fetch the interactive seat map for a flight segment.
     */
    public function seatmap(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'integer'],
            'flight_number' => ['required', 'string', 'max:20'],
            'date' => ['required', 'date'],
        ]);

        $providerConfig = $this->resolveProvider((int) $validated['provider_id']);

        if (! $providerConfig) {
            return $this->error('Provider not found.', 404);
        }

        try {
            $provider = ProviderFactory::make($providerConfig);

            if (! method_exists($provider, 'getSeatMap')) {
                return $this->error('Seat maps are not supported for this provider.', 422);
            }

            $seatMap = $provider->getSeatMap(
                $validated['flight_number'],
                $validated['date'],
            );

            return $this->success($seatMap);
        } catch (Exception $e) {
            Log::error('API seat map fetch failed: '.$e->getMessage(), [
                'provider_id' => $validated['provider_id'],
                'flight_number' => $validated['flight_number'],
            ]);

            return $this->error('Failed to fetch seat map: '.$e->getMessage(), 422);
        }
    }

    /**
     * Price a selected flight including seats and ancillary services.
     */
    public function price(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge([
            'uuid' => ['required', 'string'],
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.type' => ['nullable', 'string', 'in:adult,child,infant'],
        ], $this->extrasValidationRules()));

        $searchParams = Cache::get("flight_search_{$validated['uuid']}");
        $cachedOffer = is_array($searchParams) ? ($searchParams['selected_offer'] ?? null) : null;

        if (! $cachedOffer) {
            return $this->error('No flight selected or session expired. Please select a flight first.', 410);
        }

        $providerConfig = $this->resolveProvider((int) $cachedOffer['provider_id']);

        if (! $providerConfig) {
            return $this->error('Provider not found.', 404);
        }

        $flight = $cachedOffer['flight'];
        $passengers = $validated['passengers'];
        $extras = $validated['extras'] ?? [];

        try {
            $provider = ProviderFactory::make($providerConfig);
            $pricingSummary = $this->flightBookingPricing->summarize(
                $provider,
                $flight,
                $passengers,
                $extras,
                is_array($searchParams) ? $searchParams : [],
            );

            $mappedItinerary = $this->flightBookingPricing->mapItinerary(
                $flight,
                (string) ($cachedOffer['reservation_type'] ?? 'NN'),
            );

            $providerPricingVerified = false;

            if (method_exists($provider, 'getPricing')) {
                $fareResponse = $provider->getPricing($mappedItinerary, $passengers);
                $providerPricingVerified = $this->isProviderResponseSuccessful($fareResponse);
            }

            Cache::put(
                "flight_search_{$validated['uuid']}",
                array_merge(is_array($searchParams) ? $searchParams : [], [
                    'passengers' => $passengers,
                    'extras' => $pricingSummary['extras'],
                ]),
                now()->addMinutes(60),
            );

            return $this->success([
                'uuid' => $validated['uuid'],
                'currency' => $pricingSummary['currency'],
                'base_fare' => $pricingSummary['base_fare'],
                'seats_total' => $pricingSummary['seats']['total'],
                'ancillary_total' => $pricingSummary['ancillaries']['total'],
                'grand_total' => $pricingSummary['grand_total'],
                'seats' => $pricingSummary['seats'],
                'ancillaries' => $pricingSummary['ancillaries'],
                'provider_pricing_verified' => $providerPricingVerified,
            ], 'Price calculated successfully.');
        } catch (Exception $e) {
            Log::error('API flight price failed: '.$e->getMessage(), [
                'uuid' => $validated['uuid'],
            ]);

            return $this->error('Failed to calculate price: '.$e->getMessage(), 422);
        }
    }

    /**
     * Book a flight — finalize with passengers and issue ticket.
     */
    public function book(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge([
            'uuid' => ['required', 'string'],
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.type' => ['nullable', 'string', 'in:adult,child,infant'],
            'passengers.*.first_name' => ['nullable', 'string', 'alpha:ascii'],
            'passengers.*.last_name' => ['nullable', 'string', 'alpha:ascii'],
            'passengers.*.dob' => ['nullable', 'date'],
            'passengers.*.gender' => ['nullable', 'string', 'in:M,F'],
            'passengers.*.passport_number' => ['nullable', 'string'],
            'passengers.*.passport_expiry' => ['nullable', 'date'],
            'passengers.*.passport_issue_country' => ['nullable', 'string', 'size:3'],
            'passengers.*.nationality' => ['nullable', 'string', 'size:3'],
            'customer' => ['required', 'array'],
            'customer.first_name' => ['required', 'string'],
            'customer.last_name' => ['required', 'string'],
            'customer.email' => ['nullable', 'email'],
            'customer.phone' => ['nullable', 'string'],
            'ticketing_mode' => ['nullable', 'string', 'in:final,draft'],
        ], $this->extrasValidationRules()), [
            'passengers.*.first_name.alpha' => 'Passenger first name must contain letters only.',
            'passengers.*.last_name.alpha' => 'Passenger last name must contain letters only.',
        ]);

        $searchParams = Cache::get("flight_search_{$validated['uuid']}");
        $cachedOffer = $searchParams['selected_offer'] ?? null;

        if (! $cachedOffer) {
            return $this->error('No flight selected or session expired. Please select a flight first.', 410);
        }

        $providerId = (int) $cachedOffer['provider_id'];
        $flight = $cachedOffer['flight'];
        $reservationType = $cachedOffer['reservation_type'] ?? 'NN';
        $passengers = $validated['passengers'];
        $customer = $validated['customer'];
        $extras = $validated['extras'] ?? $searchParams['extras'] ?? [];

        $providerConfig = $this->resolveProvider($providerId);

        if (! $providerConfig) {
            return $this->error('Provider not found.', 404);
        }

        $provider = ProviderFactory::make($providerConfig);
        $providerConfig->update(['last_used_at' => now()]);

        $pricingSummary = $this->flightBookingPricing->summarize(
            $provider,
            $flight,
            $passengers,
            $extras,
            is_array($searchParams) ? $searchParams : [],
        );

        $mappedItinerary = $this->flightBookingPricing->mapItinerary($flight, $reservationType);
        $totalPrice = $pricingSummary['grand_total'];
        $currency = $pricingSummary['currency'];
        $extras = $pricingSummary['extras'];

        $issuer = $request->user();
        if (! $issuer instanceof User) {
            return $this->error('An authenticated user is required.', 401);
        }

        $isDraft = ($validated['ticketing_mode'] ?? 'final') === 'draft';

        // Validate wallet balance for final (issued) bookings only.
        if (! $isDraft) {
            try {
                $source = app(DetermineFinancialSource::class)->execute(
                    (string) $providerConfig->airline_code,
                    $currency
                );

                if ($source->usesMasterAgencySupply()) {
                    app(ProcessWalletTransactions::class)->assertCanIssueForAmounts([
                        strtoupper($currency) => $totalPrice,
                    ], $issuer);
                }
            } catch (InsufficientWalletBalanceException $e) {
                return $this->error($e->getMessage(), 402);
            }
        }

        // Book via provider
        $providerPayload = [
            'passengers' => $passengers,
            'contact' => $customer,
            'itinerary' => $mappedItinerary,
            'extras' => $extras,
            'reservation_type' => $reservationType,
            'ticketing_mode' => $isDraft ? 'draft' : 'final',
        ];

        try {
            $fareResponse = $provider->getPricing($mappedItinerary, $passengers);

            if (! $this->isProviderResponseSuccessful($fareResponse)) {
                return $this->error('Pricing verification failed. Please try again.', 422);
            }

            $bookingResponse = $provider->createBooking($providerPayload);

            if (! $this->isProviderResponseSuccessful($bookingResponse)) {
                return $this->error('Booking failed with the airline. Please try again.', 422);
            }

            $pnr = $this->extractPnrLocator($bookingResponse);

            if (! $pnr) {
                return $this->error('Airline did not return a valid booking reference.', 502);
            }
        } catch (Throwable $e) {
            report($e);

            return $this->error('Failed to communicate with the airline: '.$e->getMessage(), 502);
        }

        // Create order in database
        $order = DB::transaction(function () use ($isDraft, $providerConfig, $totalPrice, $currency, $pnr, $flight, $passengers, $customer, $issuer, $cachedOffer, $pricingSummary, $extras): Order {
            $order = Order::query()->create([
                'owner_type' => get_class($issuer),
                'owner_id' => $issuer->id,
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
                'product_subtype' => ($cachedOffer['is_round_trip'] ?? false) ? 'roundtrip' : 'oneway',
                'provider' => 'videcom',
                'provider_reference' => $pnr,
                'item_details' => [
                    'pnr' => $pnr,
                    'airline_code' => $providerConfig->airline_code,
                    'outbound_provider_id' => $cachedOffer['outbound_provider_id'] ?? null,
                    'return_provider_id' => $cachedOffer['return_provider_id'] ?? null,
                    'segments' => $flight['segments'] ?? [$flight],
                    'passengers' => $passengers,
                    'customer' => $customer,
                    'seats' => $extras['seats'] ?? [],
                    'selected_services' => $extras['selected_services'] ?? [],
                    'pricing_summary' => [
                        'base_fare' => $pricingSummary['base_fare'],
                        'seats_total' => $pricingSummary['seats']['total'],
                        'ancillary_total' => $pricingSummary['ancillaries']['total'],
                        'grand_total' => $pricingSummary['grand_total'],
                    ],
                    'ticketing_mode' => $isDraft ? 'draft' : 'final',
                ],
                'product_details' => [
                    'segments' => $flight['segments'] ?? [$flight],
                    'currency' => strtoupper($currency),
                ],
                'net_fare' => $totalPrice,
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

            return $this->success(
                $this->formatOrder($order->fresh()->load('items')),
                'Draft reservation created. Issue the ticket with POST /flights/{booking}/tickets/issue when ready.',
                201,
            );
        }

        // Apply financial source and process wallet transactions
        app(ApplyFinancialSourceAndCommission::class)->execute($order);

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
            app(ProcessWalletTransactions::class)->execute($order, $issuer);
        }

        if ($financialSources->contains('own_credentials')) {
            app(ProcessProviderWalletTransactions::class)->execute($order);
        }

        // Post to ledger (non-blocking)
        try {
            app(InitializeTenantLedger::class)->execute((string) $order->currency);
            app(PostToLedger::class)->execute($order, includeOwnCredentials: true);
        } catch (Throwable $e) {
            report($e);
            Log::warning('API booking ledger posting failed.', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        $order->refresh()->load('items');

        return $this->success($this->formatOrder($order), 'Booking confirmed successfully.', 201);
    }

    protected function resolveProvider(int $providerId): mixed
    {
        return $this->providerResolver->getAllActiveProviders()->firstWhere('id', $providerId);
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

    protected function isProviderResponseSuccessful(mixed $response): bool
    {
        if (is_array($response)) {
            return ! isset($response['error']) || empty($response['error']);
        }

        return (bool) $response;
    }

    protected function formatOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'total' => (float) $order->grand_total,
            'currency' => $order->currency,
            'issued_at' => $order->issued_at?->toISOString(),
            'created_at' => $order->created_at->toISOString(),
            'items' => $order->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'product_type' => $item->product_type,
                    'product_subtype' => $item->product_subtype,
                    'provider_reference' => $item->provider_reference,
                    'ticket_number' => $item->ticket_number,
                    'status' => $item->status,
                    'total' => (float) $item->total_amount,
                    'currency' => $item->currency,
                ];
            })->values(),
        ];
    }

    protected function filterProvidersByRouteAvailability(Collection $providers, string $origin, string $destination): Collection
    {
        $providers = collect($providers);

        if (! $this->globalFlightCacheSettingsService->isRouteAvailabilityEnabled()) {
            return $providers;
        }

        $filtered = $providers->filter(function ($provider) use ($origin, $destination) {
            $hasFlights = $this->routeAvailabilityService->hasFlights(
                (string) $provider->airline_code,
                $origin,
                $destination,
            );

            return $hasFlights !== false;
        });

        return $filtered->isNotEmpty() ? $filtered : $providers;
    }

    protected function fetchProviderFlights($providerConfig, array $searchParams): array
    {
        $provider = ProviderFactory::make($providerConfig);

        $params = [
            'origin' => strtoupper((string) $searchParams['origin']),
            'destination' => strtoupper((string) $searchParams['destination']),
            'date' => (string) $searchParams['date'],
            'adults' => (int) ($searchParams['adults'] ?? 1),
            'children' => (int) ($searchParams['children'] ?? 0),
            'infants' => (int) ($searchParams['infants'] ?? 0),
        ];

        $providerFlights = collect($provider->searchAvailability($params));
        $providerConfig->update(['last_used_at' => now()]);

        $cabinClass = $searchParams['cabin_class'] ?? 'all';
        $filtered = CabinClassFilter::filter($providerFlights->all(), $cabinClass);

        $sorted = collect($filtered)->sortBy(function (mixed $offer): float {
            if ($offer instanceof \App\DTOs\Airline\FlightOption) {
                return (float) ($offer->pricing['total'] ?? 0);
            }

            return (float) data_get($offer, 'pricing.total', 0);
        })->values()->all();

        return $sorted;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function extrasValidationRules(): array
    {
        return [
            'extras' => ['nullable', 'array'],
            'extras.seats' => ['nullable', 'array'],
            'extras.seats.*' => ['nullable', 'array'],
            'extras.seats.*.*' => ['nullable', 'string', 'max:12'],
            'extras.selected_services' => ['nullable', 'array'],
            'extras.selected_services.*.code' => ['nullable', 'string', 'max:50'],
            'extras.selected_services.*.quantity' => ['nullable', 'integer', 'min:0'],
            'extras.selected_services.*.passengers' => ['nullable', 'array'],
            'extras.selected_services.*.passengers.*' => ['integer', 'min:0'],
        ];
    }
}
