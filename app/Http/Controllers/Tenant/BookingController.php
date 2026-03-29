<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantProvider;
use App\Services\Airline\FlightSearchService;
use App\Services\Airline\ProviderFactory;
use Exception;
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
        return Inertia::render('Tenant/Bookings/Search', [
            'searchDisplayMode' => tenant()->search_display_mode ?? 'per_offer',
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
            'adults' => 'required|integer|min:1|max:9',
            'children' => 'nullable|integer|min:0|max:9',
            'infants' => 'nullable|integer|min:0|max:9',
            'is_return' => 'nullable',
        ]);

        $validated['is_return'] = filter_var($request->input('is_return'), FILTER_VALIDATE_BOOLEAN);

        $searchUuid = (string) Str::uuid();

        // Cache search parameters for 30 minutes
        Cache::put("flight_search_{$searchUuid}", $validated, now()->addMinutes(30));

        return redirect()->route('bookings.results', ['uuid' => $searchUuid]);
    }

    /**
     * Show the results shell page.
     */
    public function results(string $uuid, Request $request)
    {
        $searchParams = Cache::get("flight_search_{$uuid}");

        if (! $searchParams) {
            return redirect()->route('bookings.index')->with('error', 'Search expired. Please search again.');
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

                // 2. Web Scraper Path (Optional Discovery)
                if ($providerConfig->provider_type === 'videcom') {
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
            'searchDisplayMode' => tenant()->search_display_mode ?? 'per_offer',
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

            $qty = $searchParams['adults'] + ($searchParams['children'] ?? 0);

            // Merge search params for the service
            $params = array_merge($searchParams, ['qty' => $qty]);

            // 1. Authenticated Command Path
            $flights = collect($provider->searchAvailability($params));

            // 2. Web Scraper Path (Optional Discovery)
            if ($providerConfig->provider_type === 'videcom') {
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
     * Show the passenger info entry page for a selected flight.
     */
    public function select(Request $request): Response
    {
        $validated = $request->validate([
            'uuid' => 'required|string',
            'provider_id' => 'required|exists:tenant_providers,id',
            'flight' => 'required|array',
        ]);

        $searchParams = Cache::get("flight_search_{$validated['uuid']}");

        return Inertia::render('Tenant/Bookings/PassengerInfo', [
            'uuid' => $validated['uuid'],
            'provider_id' => $validated['provider_id'],
            'flight' => $validated['flight'],
            'searchParams' => $searchParams,
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
            'passengers' => 'required|array|min:1',
            'passengers.*.type' => 'required|in:adult,child,infant',
            'passengers.*.first_name' => 'required|string',
            'passengers.*.last_name' => 'required|string',
            'passengers.*.dob' => 'nullable|date',
            'passengers.*.gender' => 'required|in:M,F',
            'passengers.*.passport_number' => 'nullable|string',
            'passengers.*.passport_expiry' => 'nullable|date',
            'customer.first_name' => 'required|string',
            'customer.last_name' => 'required|string',
            'customer.email' => 'required|email',
            'customer.phone' => 'nullable|string',
        ]);

        // Create Customer
        $customer = \App\Models\Tenant\Customer::firstOrCreate(
            ['email' => $validated['customer']['email']],
            $validated['customer']
        );

        // Generate a fake PNR for now, as we aren't actually calling the airline API yet in this iteration
        $pnr = strtoupper(\Illuminate\Support\Str::random(6));
        $totalPrice = collect($validated['flight']['pricing'] ?? [])->sum('total') ?: ($validated['flight']['pricing']['total'] ?? 0);
        $currency = $validated['flight']['pricing'][0]['currency'] ?? ($validated['flight']['pricing']['currency'] ?? 'USD');

        // Create Booking
        $booking = \App\Models\Tenant\Booking::create([
            'customer_id' => $customer->id,
            'pnr' => $pnr,
            'tenant_provider_id' => $validated['provider_id'],
            'status' => 'pending',
            'total_price' => $totalPrice,
            'currency' => $currency,
            'created_by' => auth()->id(),
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

        return redirect()->route('bookings.show', $booking)->with('success', 'Booking created successfully!');
    }

    /**
     * Show the booking receipt.
     */
    public function show(\App\Models\Tenant\Booking $booking): Response
    {
        $booking->load(['customer', 'passengers', 'flightSegments', 'provider', 'createdBy']);

        return Inertia::render('Tenant/Bookings/Show', [
            'booking' => $booking,
        ]);
    }
}
