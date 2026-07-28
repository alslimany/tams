<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\CreateOrderFromHotelBooking;
use App\Actions\Finance\ProcessHotelProviderWalletTransactions;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Hotel\HotelBookRequest;
use App\Http\Requests\Tenant\Hotel\HotelSearchRequest;
use App\Http\Requests\Tenant\Hotel\HotelSelectRequest;
use App\Models\Country;
use App\Models\ProviderLocation;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantHotelProvider;
use App\Models\User;
use App\Notifications\Orders\HotelBooked;
use App\Notifications\Orders\OrderContact;
use App\Services\AgencyNetwork\MerchantAgencyWalletManager;
use App\Services\Hotels\HotelApiException;
use App\Services\Hotels\HotelAvailabilityPayloadFactory;
use App\Services\Hotels\HotelProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class HotelBookingController extends Controller
{
    public function __construct(
        protected HotelProviderManager $providerManager,
        protected HotelAvailabilityPayloadFactory $availabilityPayloadFactory,
        protected CreateOrderFromHotelBooking $createOrderFromHotelBooking,
        protected ProcessHotelProviderWalletTransactions $hotelProviderWalletTransactions,
        protected MerchantAgencyWalletManager $merchantAgencyWalletManager,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Tenant/Hotels/Search');
    }

    public function autocomplete(Request $request): JsonResponse
    {
        try {
            $query = trim((string) $request->query('q', ''));
            $locale = app()->getLocale();

            // --- DB-backed autocomplete (fast, no external API call) ---
            $dbResults = ProviderLocation::query()
                ->forProvider('3t')
                ->active()
                ->search($query)
                ->orderByRaw("FIELD(location_type, 'city', 'hotel') ASC")
                ->limit(30)
                ->get();

            if ($dbResults->isNotEmpty()) {
                $destinations = $dbResults->map(fn (ProviderLocation $loc): array => [
                    'code' => $loc->code,
                    'label' => $loc->translatedName($locale),
                    'country' => $loc->country_code ?? '',
                    'category' => $loc->location_type,
                    'location_type' => $loc->location_type,
                    'parent_code' => $loc->parent_code ?? '',
                ])->values()->all();

                return response()->json(['destinations' => $destinations]);
            }

            // --- Live API fallback (used when DB has not been seeded yet) ---
            $payload = $this->providerManager->provider()->autocomplete(
                $query === '' ? [] : ['termSearch' => $query],
            );
            $items = array_values(array_filter(
                is_array($payload['response'] ?? null) ? $payload['response'] : [],
                fn (mixed $item): bool => is_array($item),
            ));

            return response()->json([
                'destinations' => $this->normalizeAutocompleteDestinations($items),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Unable to load hotel destinations right now.'], 422);
        }
    }

    public function search(HotelSearchRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $uuid = (string) Str::uuid();

        Cache::put($this->searchCacheKey($uuid), $validated, now()->addMinutes(60));

        return redirect()->route('hotels.results', $uuid);
    }

    public function results(string $uuid): Response|RedirectResponse
    {
        $search = $this->pullSearch($uuid);

        if ($search === null) {
            return redirect()->route('hotels.index')->with('error', 'Hotel search expired. Please search again.');
        }

        return Inertia::render('Tenant/Hotels/Results', [
            'searchUuid' => $uuid,
            'search' => $search,
        ]);
    }

    public function availability(string $uuid, Request $request): JsonResponse
    {
        $search = $this->pullSearch($uuid);

        if ($search === null) {
            return response()->json(['message' => 'Hotel search expired. Please search again.'], 404);
        }

        $page = max(1, (int) $request->integer('page', 1));

        try {
            $providerSource = $this->providerManager->activeProviderSource();
            $payload = $this->providerManager->provider()->availability(
                $this->availabilityPayloadFactory->make($search, $page),
            );

            return response()->json([
                'hotels' => $this->normalizeAvailabilityHotels($payload, $providerSource),
                'search_code' => (string) ($payload['search_code'] ?: data_get($payload, 'raw.searchCode', '')),
                'pages' => (int) data_get($payload, 'raw.pages', 1),
                'hotels_count' => (int) data_get($payload, 'raw.hotelsCount', 0),
                'raw' => $payload['raw'],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage() ?: 'Unable to search hotels right now.'], 422);
        }
    }

    public function hotelInfo(Request $request): JsonResponse
    {
        $hotelId = (string) $request->query('hotel_id', '');
        $cityId = (string) $request->query('city_id', '');
        $source = (string) $request->query('source', '');

        if ($hotelId === '') {
            return response()->json(['message' => 'Hotel identifier is required.'], 422);
        }

        try {
            $payload = $this->providerManager->provider()->hotelDetails([
                'hotelId' => $hotelId,
                'cityId' => $cityId,
                'source' => $source,
            ]);

            return response()->json([
                'hotel' => $payload,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage() ?: 'Unable to load hotel information.'], 422);
        }
    }

    public function select(HotelSelectRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $search = $this->pullSearch((string) $validated['search_uuid']);

        if ($search === null) {
            return redirect()->route('hotels.index')->with('error', 'Hotel search expired. Please search again.');
        }

        try {
            $rateKeys = array_values(array_filter(
                (array) ($validated['rate_keys'] ?? [$validated['rate_key']]),
                fn (mixed $k): bool => is_string($k) && $k !== '',
            ));

            if (empty($rateKeys)) {
                $rateKeys = [(string) $validated['rate_key']];
            }

            $ratePayload = $this->providerManager->provider()->checkRate([
                'rooms' => array_map(fn (string $key): array => ['ratekey' => $key], $rateKeys),
                'language' => (string) ($search['language'] ?? 'fr-FR'),
                'searchCode' => (string) ($validated['raw']['search_code'] ?? ''),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage() ?: 'Unable to verify hotel rate.');
        }

        $bookingUuid = (string) Str::uuid();
        $providerSource = $request->input('provider_source', data_get($validated, 'raw.provider_source'));
        $selectedOffer = $this->normalizeSelectedOffer($validated, $ratePayload, is_array($providerSource) ? $providerSource : null);

        Cache::put($this->bookingCacheKey($bookingUuid), [
            'search' => $search,
            'selected_offer' => $selectedOffer,
            'check_rate' => $ratePayload,
            'created_at' => now()->toISOString(),
        ], now()->addMinutes(60));

        return redirect()->route('hotels.details', $bookingUuid);
    }

    public function details(string $uuid): Response|RedirectResponse
    {
        $booking = $this->pullBooking($uuid);

        if ($booking === null) {
            return redirect()->route('hotels.index')->with('error', 'Selected hotel offer expired. Please search again.');
        }

        $hotelProvider = $this->providerManager->activeProvider();
        $civilityOptions = $hotelProvider instanceof TenantHotelProvider
            ? $hotelProvider->resolvedCivilityCodes()
            : [
                ['value' => 'Mr', 'label' => 'Mr'],
                ['value' => 'Mme', 'label' => 'Mme'],
                ['value' => 'Mlle', 'label' => 'Mlle'],
                ['value' => 'Enf', 'label' => 'Enf'],
            ];

        return Inertia::render('Tenant/Hotels/Details', [
            'bookingUuid' => $uuid,
            'search' => $booking['search'],
            'selectedOffer' => $booking['selected_offer'],
            'rateKeys' => array_values(array_filter(
                (array) ($booking['selected_offer']['rate_keys'] ?? [$booking['selected_offer']['rate_key'] ?? '']),
                fn (mixed $k): bool => is_string($k) && $k !== '',
            )),
            'civilityOptions' => $civilityOptions,
            'countries' => Country::orderBy('name_en')
                ->get(['alpha2', 'alpha3', 'name_en', 'name_ar', 'name_fr']),
        ]);
    }

    public function book(HotelBookRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $cached = $this->pullBooking((string) $validated['booking_uuid']);

        if ($cached === null) {
            return redirect()->route('hotels.index')->with('error', 'Selected hotel offer expired. Please search again.');
        }

        $selectedOffer = $cached['selected_offer'];
        $hotelProvider = $this->providerManager->activeProvider();
        $providerSource = is_array($selectedOffer['provider_source'] ?? null) ? $selectedOffer['provider_source'] : [];

        if (! $hotelProvider instanceof TenantHotelProvider) {
            return back()->with('error', '3T hotel provider is not configured.');
        }

        $currency = strtoupper((string) ($selectedOffer['currency'] ?? 'USD'));
        $amount = round((float) ($selectedOffer['provider_price'] ?? $selectedOffer['price'] ?? 0), 2);

        try {
            if ((string) data_get($providerSource, 'source_type') === 'agency_network') {
                $issuer = $request->user();

                if (! $issuer instanceof User) {
                    return back()->with('error', 'Authentication is required to book hotels.');
                }

                $this->merchantAgencyWalletManager->assertCanWithdrawForSource($issuer, $providerSource, $currency, round((float) ($selectedOffer['price'] ?? 0), 2));
            }

            $this->hotelProviderWalletTransactions->assertCanWithdrawForSource($providerSource, $hotelProvider, $currency, $amount);
        } catch (InsufficientWalletBalanceException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $issuer = $request->user();

        if (! $issuer instanceof User) {
            return back()->with('error', 'Authentication is required to book hotels.');
        }

        try {
            $rooms = $this->bookingRoomsPayload($validated, $selectedOffer);
            $bookingPayload = [
                'language' => (string) data_get($cached, 'search.language', 'fr-FR'),
                'recommandations' => (string) ($validated['recommandations'] ?? ''),
                'searchCode' => (string) ($selectedOffer['search_code'] ?? ''),
                'tokenForBook' => (string) data_get($cached, 'check_rate.token_for_book', ''),
                'rooms' => $rooms,
                'payment' => [
                    'card' => '',
                    'ccv' => '',
                    'expire' => '',
                ],
                'customer' => $this->customerPayload($validated),
            ];

            $providerBooking = $this->providerManager->provider()->book($bookingPayload);

            $order = $this->createOrderFromHotelBooking->create(
                userId: $issuer->id,
                booking: $providerBooking,
                selectedOffer: $selectedOffer,
                customer: $this->customerPayload($validated),
                rooms: $rooms,
                search: $cached['search'],
                provider: $hotelProvider,
                providerSource: $providerSource,
            );

            if ((string) data_get($providerSource, 'source_type') === 'agency_network') {
                $order->loadMissing('items');

                foreach ($order->items as $item) {
                    $this->merchantAgencyWalletManager->withdrawForOrderItem($order, $item, $issuer);
                }
            }

            $this->hotelProviderWalletTransactions->execute($order, $hotelProvider);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception instanceof HotelApiException ? $exception->getMessage() : 'Unable to complete hotel booking right now.');
        }

        $contact = OrderContact::fromOrder($order);
        if (filled($contact->email) || filled($contact->phone)) {
            $order->loadMissing('items');
            $contact->notify(new HotelBooked($order, $order->items->first()));
        }

        return redirect()->route('orders.show', $order)->with('success', 'Hotel booking submitted successfully.');
    }

    public function cancel(Order $order, OrderItem $item): RedirectResponse
    {
        if ($item->order_id !== $order->id || (string) $item->product_type !== 'hotel') {
            abort(404);
        }

        $hotelProvider = $this->providerManager->activeProvider();

        if (! $hotelProvider instanceof TenantHotelProvider) {
            return back()->with('error', '3T hotel provider is not configured.');
        }

        try {
            try {
                $payload = $this->providerManager->provider()->cancel([
                    'bookingId' => data_get($item->item_details, 'booking_id'),
                    'bookingSource' => data_get($item->item_details, 'booking_source'),
                ]);
            } catch (HotelApiException $exception) {
                if ($this->isThreeTCancellationRequestCreated($exception)) {
                    $this->markHotelCancellationRequested($order, $item, $exception->context()['response'] ?? [], $exception->getMessage());

                    return back()->with('success', 'Auto cancellation was denied by 3T, but a cancellation request has been sent for your booking.');
                }

                throw $exception;
            }

            $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];
            $canceled = (bool) ($response['canceled'] ?? false);

            if (! $canceled) {
                return back()->with('error', (string) ($payload['message'] ?? 'Hotel cancellation was not confirmed by provider.'));
            }

            $cancellationFee = round((float) ($response['cancellationFee'] ?? 0), 2);
            $refundAmount = max(0.0, round((float) data_get($item->item_details, 'provider_cost', $item->net_fare ?? 0) - $cancellationFee, 2));

            DB::transaction(function () use ($order, $item, $hotelProvider, $payload, $cancellationFee, $refundAmount): void {
                $details = (array) $item->item_details;
                $details['cancellation'] = [
                    'provider_response' => $payload,
                    'cancellation_fee' => $cancellationFee,
                    'refund_amount' => $refundAmount,
                    'cancelled_at' => now()->toISOString(),
                ];

                if ($refundAmount > 0) {
                    $wallet = $hotelProvider->getOrCreateCurrencyWallet((string) ($item->currency ?? $order->currency ?? 'USD'));
                    $refund = $wallet->depositFloat($refundAmount, [
                        'type' => 'cancellation',
                        'provider_type' => 'hotel',
                        'hotel_provider_type' => $hotelProvider->provider_type,
                        'provider_id' => $hotelProvider->id,
                        'tenant_id' => tenant()?->id,
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'product_type' => 'hotel',
                        'provider_reference' => (string) $item->provider_reference,
                        'cancellation_fee' => $cancellationFee,
                    ]);

                    $details['cancellation']['provider_wallet_transaction_id'] = $refund->uuid;
                }

                $item->update([
                    'status' => 'cancelled',
                    'refund_status' => $refundAmount > 0 ? 'refunded' : 'none',
                    'item_details' => $details,
                ]);

                $order->update([
                    'status' => 'cancelled',
                    'amount_refunded' => $refundAmount,
                ]);
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage() ?: 'Unable to cancel hotel booking right now.');
        }

        return back()->with('success', 'Hotel booking cancelled successfully.');
    }

    protected function isThreeTCancellationRequestCreated(HotelApiException $exception): bool
    {
        $response = $exception->context()['response'] ?? [];
        $message = strtolower($exception->getMessage());

        return (string) ($response['method'] ?? '') === 'cancel'
            && (string) ($response['errorCode'] ?? '') === '502'
            && str_contains($message, 'cancellation request')
            && str_contains($message, 'sent');
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    protected function markHotelCancellationRequested(Order $order, OrderItem $item, array $providerResponse, string $message): void
    {
        DB::transaction(function () use ($order, $item, $providerResponse, $message): void {
            $details = (array) $item->item_details;
            $details['cancellation_request'] = [
                'status' => 'requested',
                'message' => $message,
                'provider_response' => $providerResponse,
                'requested_at' => now()->toISOString(),
                'auto_cancellation_denied' => true,
            ];

            $item->update([
                'status' => 'cancellation',
                'item_details' => $details,
            ]);

            $order->update(['status' => 'cancellation']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeAutocompleteDestinations(array $items): array
    {
        return array_values(array_map(function (array $item): array {
            // Preserve the city/hotel code so the frontend can populate city_id
            $code = (string) ($item['cityId'] ?? $item['hotelCode'] ?? $item['id'] ?? '');

            return collect([
                ...$item,
                'code' => $code,
                'label' => (string) ($item['label'] ?? $item['name'] ?? ''),
                'country' => (string) ($item['country'] ?? ''),
                'category' => (string) ($item['category'] ?? ''),
            ])->except(['id', 'cityId', 'value'])->all();
        }, $items));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeAvailabilityHotels(array $payload, ?array $providerSource = null): array
    {
        $items = is_array($payload['response'] ?? null) ? $payload['response'] : [];
        $searchCode = (string) ($payload['search_code'] ?: data_get($payload, 'raw.searchCode', ''));
        $markupPercent = $this->hotelMarkupPercent();

        return array_values(array_map(function (array $item) use ($searchCode, $markupPercent, $providerSource): array {
            $hotel = is_array($item['hotel'] ?? null) ? $item['hotel'] : [];

            return [
                'hotel' => $hotel,
                'hotel_id' => (string) ($hotel['hotelId'] ?? $item['hotelId'] ?? ''),
                'hotel_uid' => (string) ($hotel['hotelUid'] ?? ''),
                'name' => (string) ($hotel['name'] ?? $hotel['hotelName'] ?? $item['hotelName'] ?? 'Hotel'),
                'source' => $item['source'] ?? null,
                'provider_source' => $providerSource,
                'rooms' => $this->flattenRooms((array) ($item['rooms'] ?? []), $item, $searchCode, $markupPercent, $providerSource),
                'raw' => $item,
            ];
        }, array_filter($items, fn (mixed $item): bool => is_array($item))));
    }

    /**
     * @param  array<int, mixed>  $groups
     * @param  array<string, mixed>  $hotelItem
     * @return array<int, array<string, mixed>>
     */
    protected function flattenRooms(array $groups, array $hotelItem, string $searchCode, float $markupPercent = 0, ?array $providerSource = null): array
    {
        $rooms = [];

        foreach ($groups as $group) {
            foreach ((array) $group as $room) {
                if (! is_array($room)) {
                    continue;
                }

                $providerPrice = round((float) ($room['price'] ?? $room['total'] ?? 0), 2);
                $sellingPrice = $this->applyHotelMarkup($providerPrice, $markupPercent);

                $rooms[] = [
                    'rate_key' => (string) ($room['rateKey'] ?? $room['ratekey'] ?? ''),
                    'room_name' => (string) ($room['roomName'] ?? $room['name'] ?? $room['description'] ?? 'Room'),
                    'board_name' => (string) ($room['boardName'] ?? $room['board'] ?? ''),
                    'price' => $sellingPrice,
                    'provider_price' => $providerPrice,
                    'markup_percent' => $markupPercent,
                    'markup_amount' => round($sellingPrice - $providerPrice, 2),
                    'currency' => strtoupper((string) ($room['currency'] ?? 'USD')),
                    'available' => (bool) ($room['available'] ?? true),
                    'association_id' => $room['associationId'] ?? null,
                    'cancellation_policies' => is_array($room['cancellationPolicies'] ?? null) ? $room['cancellationPolicies'] : [],
                    'no_show' => $room['noShow'] ?? null,
                    'search_code' => $searchCode,
                    'source' => $hotelItem['source'] ?? null,
                    'provider_source' => $providerSource,
                    'raw' => $room,
                ];
            }
        }

        return $rooms;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $ratePayload
     * @return array<string, mixed>
     */
    protected function normalizeSelectedOffer(array $validated, array $ratePayload, ?array $providerSource = null): array
    {
        $rateKeys = array_values(array_filter(
            (array) ($validated['rate_keys'] ?? [$validated['rate_key']]),
            fn (mixed $k): bool => is_string($k) && $k !== '',
        ));

        if (empty($rateKeys)) {
            $rateKeys = [(string) $validated['rate_key']];
        }

        $markupPercent = $this->hotelMarkupPercent();
        $checkRateRooms = $this->checkRateRooms($ratePayload);
        $providerPrice = $this->providerPriceFromCheckRate($rateKeys, $checkRateRooms, (float) $validated['price']);
        $sellingPrice = $this->applyHotelMarkup($providerPrice, $markupPercent);

        return [
            'hotel_id' => (string) $validated['hotel_id'],
            'hotel_uid' => (string) ($validated['hotel_uid'] ?? ''),
            'hotel_name' => (string) $validated['hotel_name'],
            'source' => $validated['source'],
            'rate_key' => (string) $validated['rate_key'],
            'rate_keys' => $rateKeys,
            'room_name' => (string) ($validated['room_name'] ?? 'Room'),
            'board_name' => (string) ($validated['board_name'] ?? ''),
            'price' => $sellingPrice,
            'provider_price' => $providerPrice,
            'markup_percent' => $markupPercent,
            'markup_amount' => round($sellingPrice - $providerPrice, 2),
            'currency' => strtoupper((string) $validated['currency']),
            'available' => (bool) ($validated['available'] ?? true),
            'cancellation_policies' => $validated['cancellation_policies'] ?? [],
            'check_rate_rooms' => $this->applyMarkupToCheckRateRooms($checkRateRooms, $markupPercent),
            'search_code' => (string) ($validated['raw']['search_code'] ?? $ratePayload['search_code'] ?? ''),
            'token_for_book' => (string) ($ratePayload['token_for_book'] ?? ''),
            'provider_source' => $providerSource,
            'hotel' => [
                'hotel_id' => (string) $validated['hotel_id'],
                'hotel_uid' => (string) ($validated['hotel_uid'] ?? ''),
                'name' => (string) $validated['hotel_name'],
            ],
            'raw' => [
                'selected' => $validated['raw'] ?? [],
                'check_rate' => $ratePayload['raw'] ?? [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $selectedOffer
     * @return array<int, array<string, mixed>>
     */
    protected function bookingRoomsPayload(array $validated, array $selectedOffer): array
    {
        return array_values(array_map(function (array $room): array {
            return [
                'ratekey' => (string) $room['rate_key'],
                'evening' => '',
                'supplements' => [],
                'paxes' => array_values(array_map(function (array $pax): array {
                    $payload = [
                        'civility' => (string) $pax['civility'],
                        'firstName' => (string) $pax['first_name'],
                        'lastName' => (string) $pax['last_name'],
                    ];

                    if (($payload['civility'] ?? '') === 'Enf' && isset($pax['age'])) {
                        $payload['age'] = (int) $pax['age'];
                    }

                    return $payload;
                }, $room['paxes'] ?? [])),
            ];
        }, $validated['rooms'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    protected function customerPayload(array $validated): array
    {
        $customer = $validated['customer'] ?? [];

        return [
            'firstName' => (string) ($customer['first_name'] ?? ''),
            'lastName' => (string) ($customer['last_name'] ?? ''),
            'email' => (string) ($customer['email'] ?? ''),
            'mobile' => (string) ($customer['mobile'] ?? ''),
            'country' => (string) ($customer['country'] ?? ''),
            'city' => (string) ($customer['city'] ?? ''),
        ];
    }

    protected function hotelMarkupPercent(): float
    {
        $provider = $this->providerManager->activeProvider();

        return $provider instanceof TenantHotelProvider
            ? max(0.0, $provider->markupForProductType('hotel'))
            : 0.0;
    }

    protected function applyHotelMarkup(float $amount, float $markupPercent): float
    {
        return round($amount + (($amount * $markupPercent) / 100), 2);
    }

    /**
     * @param  array<string, mixed>  $ratePayload
     * @return array<int, array<string, mixed>>
     */
    protected function checkRateRooms(array $ratePayload): array
    {
        $groups = data_get($ratePayload, 'response.0.rooms', []);

        return collect(is_array($groups) ? $groups : [])
            ->flatMap(fn (mixed $group): array => is_array($group) ? $group : [])
            ->filter(fn (mixed $room): bool => is_array($room))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $rateKeys
     * @param  array<int, array<string, mixed>>  $rooms
     */
    protected function providerPriceFromCheckRate(array $rateKeys, array $rooms, float $fallback): float
    {
        $matchedTotal = collect($rateKeys)
            ->sum(function (string $rateKey) use ($rooms): float {
                $room = collect($rooms)->first(fn (array $room): bool => (string) ($room['rateKey'] ?? $room['ratekey'] ?? '') === $rateKey);

                return round((float) ($room['price'] ?? 0), 2);
            });

        return round($matchedTotal > 0 ? $matchedTotal : $fallback, 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rooms
     * @return array<int, array<string, mixed>>
     */
    protected function applyMarkupToCheckRateRooms(array $rooms, float $markupPercent): array
    {
        return array_values(array_map(function (array $room) use ($markupPercent): array {
            $providerPrice = round((float) ($room['price'] ?? 0), 2);
            $sellingPrice = $this->applyHotelMarkup($providerPrice, $markupPercent);

            return [
                ...$room,
                'provider_price' => $providerPrice,
                'selling_price' => $sellingPrice,
                'markup_percent' => $markupPercent,
                'markup_amount' => round($sellingPrice - $providerPrice, 2),
            ];
        }, $rooms));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function pullSearch(string $uuid): ?array
    {
        $payload = Cache::get($this->searchCacheKey($uuid));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function pullBooking(string $uuid): ?array
    {
        $payload = Cache::get($this->bookingCacheKey($uuid));

        return is_array($payload) ? $payload : null;
    }

    protected function searchCacheKey(string $uuid): string
    {
        return 'hotel_search_'.$uuid;
    }

    protected function bookingCacheKey(string $uuid): string
    {
        return 'hotel_booking_'.$uuid;
    }
}
