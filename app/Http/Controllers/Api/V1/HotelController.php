<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Finance\CreateOrderFromHotelBooking;
use App\Actions\Finance\ProcessHotelProviderWalletTransactions;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Api\Controller;
use App\Models\Tenant\Order;
use App\Models\Tenant\TenantHotelProvider;
use App\Models\User;
use App\Notifications\Orders\HotelBooked;
use App\Notifications\Orders\OrderContact;
use App\Services\AgencyNetwork\MerchantAgencyWalletManager;
use App\Services\Hotels\HotelApiException;
use App\Services\Hotels\HotelAvailabilityPayloadFactory;
use App\Services\Hotels\HotelProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class HotelController extends Controller
{
    public function __construct(
        protected HotelProviderManager $providerManager,
        protected HotelAvailabilityPayloadFactory $availabilityPayloadFactory,
        protected CreateOrderFromHotelBooking $createOrderFromHotelBooking,
        protected ProcessHotelProviderWalletTransactions $hotelProviderWalletTransactions,
        protected MerchantAgencyWalletManager $merchantAgencyWalletManager,
    ) {}

    /**
     * Destination autocomplete.
     */
    public function autocomplete(Request $request): JsonResponse
    {
        try {
            $query = trim((string) $request->query('q', ''));
            $payload = $this->providerManager->provider()->autocomplete(
                $query === '' ? [] : ['termSearch' => $query],
            );

            $items = array_values(array_filter(
                is_array($payload['response'] ?? null) ? $payload['response'] : [],
                fn (mixed $item): bool => is_array($item),
            ));

            return $this->success([
                'destinations' => $this->normalizeAutocompleteDestinations($items),
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Unable to load hotel destinations.', 422);
        }
    }

    /**
     * Search hotels and return availability.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'city' => ['required', 'string', 'max:120'],
            'city_id' => ['required', 'integer', 'min:1'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'rooms' => ['required'],
            'rooms.*.adult' => ['nullable', 'integer', 'min:1', 'max:9'],
            'rooms.*.adults' => ['nullable', 'integer', 'min:1', 'max:9'],
            'rooms.*.children' => ['nullable', 'array', 'max:6'],
            'rooms.*.children.*' => ['integer', 'min:0', 'max:17'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:10'],
            'children' => ['nullable', 'integer', 'min:0', 'max:5'],
            'children_ages' => ['nullable', 'array', 'max:5'],
            'children_ages.*' => ['integer', 'min:0', 'max:17'],
            'language' => ['nullable', 'string', 'max:10'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $request->validate([
            'rooms' => [
                function (string $attribute, mixed $value, \Closure $fail) use ($validated): void {
                    if (is_array($value) && array_is_list($value) && count($value) > 0) {
                        foreach ($value as $index => $room) {
                            if (! is_array($room)) {
                                $fail("The rooms.{$index} field must be an object.");

                                return;
                            }

                            $adults = $room['adult'] ?? $room['adults'] ?? null;

                            if (! is_numeric($adults) || (int) $adults < 1) {
                                $fail("The rooms.{$index}.adult field is required.");

                                return;
                            }
                        }

                        return;
                    }

                    if (is_numeric($value) && (int) $value >= 1 && isset($validated['adults'])) {
                        return;
                    }

                    $fail('The rooms field must be a list of room occupancies, or a room count with adults.');
                },
            ],
        ]);

        try {
            $normalizedRooms = $this->availabilityPayloadFactory->normalizeRooms($validated);
        } catch (HotelApiException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $page = max(1, (int) ($validated['page'] ?? 1));
        $searchCriteria = [
            ...$validated,
            'rooms' => $normalizedRooms,
            'language' => (string) ($validated['language'] ?? 'fr-FR'),
        ];

        $uuid = (string) Str::uuid();
        Cache::put("hotel_search_{$uuid}", $searchCriteria, now()->addMinutes(60));

        try {
            $payload = $this->providerManager->provider()->availability(
                $this->availabilityPayloadFactory->make($searchCriteria, $page),
            );

            return $this->success([
                'uuid' => $uuid,
                'hotels' => $payload['response'] ?? [],
                'search_code' => (string) ($payload['search_code'] ?: data_get($payload, 'raw.searchCode', '')),
                'pages' => (int) ($payload['pages'] ?? data_get($payload, 'raw.pages', 1)),
                'hotels_count' => (int) ($payload['hotels_count'] ?? data_get($payload, 'raw.hotelsCount', 0)),
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->error($e->getMessage() ?: 'Unable to search hotels.', 422);
        }
    }

    /**
     * Get hotel details.
     */
    public function details(Request $request): JsonResponse
    {
        $request->validate([
            'hotel_id' => ['required', 'string'],
            'city_id' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
        ]);

        try {
            $payload = $this->providerManager->provider()->hotelDetails([
                'hotelId' => (string) $request->input('hotel_id'),
                'cityId' => (string) $request->input('city_id', ''),
                'source' => (string) $request->input('source', ''),
            ]);

            return $this->success(['hotel' => $payload]);
        } catch (Throwable $e) {
            report($e);

            return $this->error($e->getMessage() ?: 'Unable to load hotel details.', 422);
        }
    }

    /**
     * Select a hotel offer (check rates) and cache for booking.
     */
    public function select(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search_uuid' => ['required', 'string'],
            'rate_key' => ['required', 'string'],
            'search_code' => ['nullable', 'string'],
            'language' => ['nullable', 'string'],
        ]);

        $search = Cache::get("hotel_search_{$validated['search_uuid']}");

        if (! $search) {
            return $this->error('Hotel search expired. Please search again.', 410);
        }

        try {
            $ratePayload = $this->providerManager->provider()->checkRate([
                'rooms' => [['ratekey' => (string) $validated['rate_key']]],
                'language' => $this->providerLanguage(
                    (string) ($validated['language'] ?? $search['language'] ?? 'fr-FR'),
                ),
                'searchCode' => (string) ($validated['search_code'] ?? ''),
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->error($e->getMessage() ?: 'Unable to verify hotel rate.', 422);
        }

        $bookingUuid = (string) Str::uuid();
        $providerWithSource = $this->providerManager->activeProviderWithSource();

        Cache::put("hotel_booking_{$bookingUuid}", [
            'search' => $search,
            'selected_offer' => $this->normalizeSelectedOfferFromCheckRate(
                (string) $validated['rate_key'],
                (string) ($validated['search_code'] ?? $ratePayload['search_code'] ?? ''),
                $ratePayload,
                is_array($providerWithSource['source'] ?? null) ? $providerWithSource['source'] : null,
            ),
            'check_rate' => $ratePayload,
            'created_at' => now()->toISOString(),
        ], now()->addMinutes(60));

        return $this->success([
            'booking_uuid' => $bookingUuid,
            'selected_offer' => $ratePayload,
        ], 'Hotel selected. Proceed to book with guest details.');
    }

    /**
     * Book a hotel with guest details and create a TAMS order.
     */
    public function book(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_uuid' => ['required', 'string'],
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.rate_key' => ['nullable', 'string'],
            'rooms.*.guests' => ['required', 'array', 'min:1'],
            'rooms.*.guests.*.civility' => ['required', 'string'],
            'rooms.*.guests.*.first_name' => ['required', 'string', 'max:100'],
            'rooms.*.guests.*.last_name' => ['required', 'string', 'max:100'],
            'rooms.*.guests.*.age' => ['nullable', 'integer', 'min:0', 'max:17'],
            'customer' => ['required', 'array'],
            'customer.first_name' => ['required', 'string', 'max:100'],
            'customer.last_name' => ['required', 'string', 'max:100'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:50'],
            'customer.mobile' => ['nullable', 'string', 'max:50'],
            'customer.country' => ['required', 'string', 'max:100'],
            'customer.city' => ['required', 'string', 'max:100'],
            'recommandations' => ['nullable', 'string', 'max:1000'],
        ]);

        $issuer = $request->user();

        if (! $issuer instanceof User) {
            return $this->error('Authentication is required to book hotels.', 401);
        }

        $cached = Cache::get("hotel_booking_{$validated['booking_uuid']}");

        if (! $cached) {
            return $this->error('Selected hotel offer expired. Please search again.', 410);
        }

        $hotelProvider = $this->providerManager->activeProvider();

        if (! $hotelProvider instanceof TenantHotelProvider) {
            return $this->error('Hotel provider is not configured.', 400);
        }

        $selectedOffer = is_array($cached['selected_offer'] ?? null) ? $cached['selected_offer'] : [];
        $providerSource = is_array($selectedOffer['provider_source'] ?? null)
            ? $selectedOffer['provider_source']
            : ($this->providerManager->activeProviderSource() ?? []);
        $search = is_array($cached['search'] ?? null) ? $cached['search'] : [];
        $fallbackRateKey = (string) ($selectedOffer['rate_key'] ?? '');
        $currency = strtoupper((string) ($selectedOffer['currency'] ?? 'LYD'));
        $providerCost = round((float) ($selectedOffer['provider_price'] ?? $selectedOffer['price'] ?? 0), 2);

        try {
            if ((string) data_get($providerSource, 'source_type') === 'agency_network') {
                $this->merchantAgencyWalletManager->assertCanWithdrawForSource(
                    $issuer,
                    $providerSource,
                    $currency,
                    round((float) ($selectedOffer['price'] ?? $providerCost), 2),
                );
            }

            $this->hotelProviderWalletTransactions->assertCanWithdrawForSource(
                $providerSource,
                $hotelProvider,
                $currency,
                $providerCost,
            );
        } catch (InsufficientWalletBalanceException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        try {
            $customer = $validated['customer'];
            $providerCustomer = [
                'firstName' => (string) $customer['first_name'],
                'lastName' => (string) $customer['last_name'],
                'email' => (string) ($customer['email'] ?? ''),
                'mobile' => (string) ($customer['mobile'] ?? $customer['phone'] ?? ''),
                'country' => (string) $customer['country'],
                'city' => (string) $customer['city'],
            ];
            $orderCustomer = [
                ...$providerCustomer,
                'first_name' => (string) $customer['first_name'],
                'last_name' => (string) $customer['last_name'],
                'phone' => (string) ($customer['phone'] ?? $customer['mobile'] ?? ''),
            ];
            $roomsPayload = $this->providerBookingRoomsPayload($validated['rooms'], $fallbackRateKey);

            $bookingPayload = [
                'language' => $this->providerLanguage((string) ($search['language'] ?? 'fr-FR')),
                'recommandations' => (string) ($validated['recommandations'] ?? ''),
                'searchCode' => (string) ($selectedOffer['search_code'] ?? ''),
                'tokenForBook' => (string) data_get($cached, 'check_rate.token_for_book', ''),
                'rooms' => $roomsPayload,
                'payment' => [
                    'card' => '',
                    'ccv' => '',
                    'expire' => '',
                ],
                'customer' => $providerCustomer,
            ];

            $providerBooking = $this->providerManager->provider()->book($bookingPayload);
            $selectedOffer = $this->enrichSelectedOfferFromBooking($selectedOffer, $providerBooking);

            $order = $this->createOrderFromHotelBooking->create(
                userId: $issuer->id,
                booking: $providerBooking,
                selectedOffer: $selectedOffer,
                customer: $orderCustomer,
                rooms: $roomsPayload,
                search: $search,
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

            Cache::forget("hotel_booking_{$validated['booking_uuid']}");

            $order->loadMissing(['owner', 'items']);

            $contact = OrderContact::fromOrder($order);
            if (filled($contact->email) || filled($contact->phone)) {
                $contact->notify(new HotelBooked($order, $order->items->first()));
            }

            return $this->success([
                'order' => $this->formatOrder($order),
                'booking' => $providerBooking,
            ], 'Hotel booked successfully.', 201);
        } catch (InsufficientWalletBalanceException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return $this->error($e instanceof HotelApiException ? $e->getMessage() : 'Unable to complete hotel booking.', 422);
        }
    }

    /**
     * @param  array<string, mixed>  $ratePayload
     * @param  array<string, mixed>|null  $providerSource
     * @return array<string, mixed>
     */
    protected function normalizeSelectedOfferFromCheckRate(
        string $rateKey,
        string $searchCode,
        array $ratePayload,
        ?array $providerSource = null,
    ): array {
        $rooms = $this->checkRateRooms($ratePayload);
        $matchedRoom = collect($rooms)->first(
            fn (array $room): bool => (string) ($room['rateKey'] ?? $room['ratekey'] ?? '') === $rateKey,
        ) ?? ($rooms[0] ?? []);

        $providerPrice = round((float) ($matchedRoom['price'] ?? 0), 2);
        $markupPercent = $this->hotelMarkupPercent();
        $sellingPrice = $this->applyHotelMarkup($providerPrice, $markupPercent);
        $currency = strtoupper((string) ($matchedRoom['currency'] ?? data_get($ratePayload, 'raw.currency', 'LYD')));

        return [
            'hotel_id' => (string) data_get($matchedRoom, 'hotelId', data_get($ratePayload, 'response.0.hotelId', '')),
            'hotel_uid' => (string) data_get($matchedRoom, 'hotelUid', ''),
            'hotel_name' => (string) data_get($matchedRoom, 'hotelName', data_get($matchedRoom, 'name', 'Hotel')),
            'source' => data_get($matchedRoom, 'source'),
            'rate_key' => $rateKey,
            'rate_keys' => [$rateKey],
            'room_name' => (string) ($matchedRoom['name'] ?? $matchedRoom['roomName'] ?? 'Room'),
            'board_name' => (string) ($matchedRoom['boardName'] ?? ''),
            'price' => $sellingPrice,
            'provider_price' => $providerPrice,
            'markup_percent' => $markupPercent,
            'markup_amount' => round($sellingPrice - $providerPrice, 2),
            'currency' => $currency,
            'available' => (bool) ($matchedRoom['available'] ?? true),
            'cancellation_policies' => $matchedRoom['cancellationPolicies'] ?? [],
            'search_code' => $searchCode,
            'token_for_book' => (string) ($ratePayload['token_for_book'] ?? ''),
            'provider_source' => $providerSource,
            'hotel' => [
                'hotel_id' => (string) data_get($matchedRoom, 'hotelId', ''),
                'hotel_uid' => (string) data_get($matchedRoom, 'hotelUid', ''),
                'name' => (string) data_get($matchedRoom, 'hotelName', data_get($matchedRoom, 'name', 'Hotel')),
            ],
            'room' => $matchedRoom,
            'raw' => [
                'check_rate' => $ratePayload['raw'] ?? [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $selectedOffer
     * @param  array<string, mixed>  $providerBooking
     * @return array<string, mixed>
     */
    protected function enrichSelectedOfferFromBooking(array $selectedOffer, array $providerBooking): array
    {
        $bookingResponse = is_array($providerBooking['response'] ?? null) ? $providerBooking['response'] : [];
        $providerTotal = round((float) ($bookingResponse['totalPurchase'] ?? 0), 2);
        $currency = strtoupper((string) ($bookingResponse['currency'] ?? $selectedOffer['currency'] ?? 'LYD'));
        $providerHotel = is_array(data_get($bookingResponse, 'booking.hotel'))
            ? data_get($bookingResponse, 'booking.hotel')
            : [];

        if ($providerTotal > 0 && round((float) ($selectedOffer['provider_price'] ?? 0), 2) <= 0) {
            $markupPercent = (float) ($selectedOffer['markup_percent'] ?? $this->hotelMarkupPercent());
            $sellingPrice = $this->applyHotelMarkup($providerTotal, $markupPercent);

            $selectedOffer['provider_price'] = $providerTotal;
            $selectedOffer['price'] = $sellingPrice;
            $selectedOffer['markup_percent'] = $markupPercent;
            $selectedOffer['markup_amount'] = round($sellingPrice - $providerTotal, 2);
        }

        $selectedOffer['currency'] = $currency;

        if ($providerHotel !== []) {
            $selectedOffer['hotel'] = [
                ...(is_array($selectedOffer['hotel'] ?? null) ? $selectedOffer['hotel'] : []),
                'hotel_id' => (string) ($providerHotel['hotelId'] ?? data_get($selectedOffer, 'hotel.hotel_id', '')),
                'hotel_uid' => (string) ($providerHotel['hotelUid'] ?? data_get($selectedOffer, 'hotel.hotel_uid', '')),
                'name' => (string) ($providerHotel['hotelName'] ?? data_get($selectedOffer, 'hotel.name', 'Hotel')),
            ];
            $selectedOffer['hotel_id'] = (string) ($providerHotel['hotelId'] ?? $selectedOffer['hotel_id'] ?? '');
            $selectedOffer['hotel_name'] = (string) ($providerHotel['hotelName'] ?? $selectedOffer['hotel_name'] ?? 'Hotel');
            $selectedOffer['source'] = $selectedOffer['source'] ?? ($providerHotel['supplierSourceId'] ?? null);
        }

        return $selectedOffer;
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
     * Normalize language for 3T book / checkRate (expects fr-FR, not fr_FR).
     */
    protected function providerLanguage(string $language): string
    {
        $normalized = str_replace('_', '-', trim($language));

        return $normalized !== '' ? $normalized : 'fr-FR';
    }

    /**
     * Map API rooms/guests into the provider book shape (ratekey + paxes).
     *
     * @param  array<int, array<string, mixed>>  $rooms
     * @return array<int, array<string, mixed>>
     */
    protected function providerBookingRoomsPayload(array $rooms, string $fallbackRateKey): array
    {
        return array_values(array_map(function (array $room) use ($fallbackRateKey): array {
            $rateKey = (string) ($room['rate_key'] ?? $fallbackRateKey);

            if ($rateKey === '') {
                throw new HotelApiException('Missing rate key for room. Select a hotel offer again.');
            }

            return [
                'ratekey' => $rateKey,
                'evening' => '',
                'supplements' => [],
                'paxes' => array_values(array_map(function (array $guest): array {
                    $payload = [
                        'civility' => (string) $guest['civility'],
                        'firstName' => (string) $guest['first_name'],
                        'lastName' => (string) $guest['last_name'],
                    ];

                    if ($payload['civility'] === 'Enf' && isset($guest['age'])) {
                        $payload['age'] = (int) $guest['age'];
                    }

                    return $payload;
                }, $room['guests'] ?? [])),
            ];
        }, $rooms));
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'subtotal' => (float) $order->subtotal,
            'tax_total' => (float) $order->tax_total,
            'grand_total' => (float) $order->grand_total,
            'amount_paid' => (float) $order->amount_paid,
            'amount_refunded' => (float) ($order->amount_refunded ?? 0),
            'currency' => $order->currency,
            'payment_method' => $order->payment_method,
            'payment_reference' => $order->payment_reference,
            'issued_at' => $order->issued_at?->toISOString(),
            'created_at' => $order->created_at?->toISOString(),
            'owner' => $order->owner ? [
                'id' => $order->owner->id,
                'name' => $order->owner->name,
                'email' => $order->owner->email,
                'role' => $order->owner->role,
            ] : null,
            'contact' => $order->contact,
            'items' => $order->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'product_type' => $item->product_type,
                    'product_subtype' => $item->product_subtype,
                    'provider' => $item->provider,
                    'provider_reference' => $item->provider_reference,
                    'ticket_number' => $item->ticket_number,
                    'status' => $item->status,
                    'total' => (float) $item->total_amount,
                    'net_fare' => (float) $item->net_fare,
                    'commission_amount' => (float) $item->commission_amount,
                    'currency' => $item->currency,
                    'product_details' => $item->product_details,
                    'item_details' => [
                        'booking_id' => data_get($item->item_details, 'booking_id'),
                        'confirmed' => data_get($item->item_details, 'confirmed'),
                        'provider_booking' => data_get($item->item_details, 'provider_booking'),
                        'comments' => data_get($item->item_details, 'comments'),
                    ],
                ];
            })->values(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeAutocompleteDestinations(array $items): array
    {
        return array_values(array_map(function (array $item): array {
            $code = (string) ($item['cityId'] ?? $item['hotelCode'] ?? $item['id'] ?? $item['uid'] ?? '');

            return [
                ...$item,
                'id' => is_numeric($code) ? (int) $code : ($item['id'] ?? $code),
                'code' => $code,
                'city_id' => is_numeric($code) ? (int) $code : null,
                'label' => (string) ($item['label'] ?? $item['name'] ?? ''),
                'country' => (string) ($item['country'] ?? ''),
                'category' => (string) ($item['category'] ?? ''),
            ];
        }, $items));
    }
}
