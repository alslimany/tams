<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\RoundTripPriceManager;
use App\Services\Airline\Videcom\VidecomAncillaryCatalog;
use App\Services\Orders\OrderNumberGenerator;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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

        $flights = [];
        if ($request->has('provider_id')) {
            $providerId = $request->input('provider_id');
            try {
                $providerConfig = TenantProvider::findOrFail($providerId);
                $provider = ProviderFactory::make($providerConfig);

                $params = $this->buildOneWayAvailabilityParams($searchParams);

                $providerFlights = collect($provider->searchAvailability($params));
                $providerConfig->update(['last_used_at' => now()]);

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
            'providers' => $providers,
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

                foreach ($returnFlights as $returnFlight) {
                    $returnFlightData = is_array($returnFlight) ? $returnFlight : (array) $returnFlight;
                    data_set($returnFlightData, 'pricing_method', 'oneway');

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

        return Inertia::render('Tenant/Bookings/PassengerInfo', [
            'uuid' => $validated['uuid'],
            'provider_id' => $validated['provider_id'],
            'flight' => $validated['flight'],
            'reservation_type' => $validated['reservation_type'],
            'is_round_trip' => filter_var($validated['is_round_trip'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'outbound_provider_id' => $validated['outbound_provider_id'] ?? null,
            'return_provider_id' => $validated['return_provider_id'] ?? null,
            'passportRequired' => $this->isInternationalFlight($validated['flight']),
            'searchParams' => $searchParams,
            'ancillaryCatalog' => $provider->getAncillaryCatalog($validated['flight'], $searchParams ?? []),
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

        $ancillaryCatalog = $provider->getAncillaryCatalog($validated['flight'], $searchParams);
        $ancillarySummary = VidecomAncillaryCatalog::selectedTotals(
            $ancillaryCatalog,
            $validated['extras']['selected_services'] ?? [],
            count($validated['passengers']),
            count($mappedItinerary)
        );

        $extras = $validated['extras'] ?? [];
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
