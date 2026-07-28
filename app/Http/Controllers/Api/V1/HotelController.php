<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Controller;
use App\Models\Tenant\TenantHotelProvider;
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
            // Preferred: rooms as occupancy list (same shape as tenant web / 3T).
            // Shortcut: rooms as count + adults (+ optional children/children_ages).
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
                'language' => str_replace('-', '_', (string) ($validated['language'] ?? $search['language'] ?? 'fr_FR')),
                'searchCode' => (string) ($validated['search_code'] ?? ''),
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->error($e->getMessage() ?: 'Unable to verify hotel rate.', 422);
        }

        $bookingUuid = (string) Str::uuid();

        Cache::put("hotel_booking_{$bookingUuid}", [
            'search' => $search,
            'selected_offer' => [
                'room' => $ratePayload['response'][0] ?? $ratePayload,
                'search_code' => (string) ($validated['search_code'] ?? ''),
                'rate_key' => (string) $validated['rate_key'],
            ],
            'check_rate' => $ratePayload,
            'created_at' => now()->toISOString(),
        ], now()->addMinutes(60));

        return $this->success([
            'booking_uuid' => $bookingUuid,
            'selected_offer' => $ratePayload,
        ], 'Hotel selected. Proceed to book with guest details.');
    }

    /**
     * Book a hotel with guest details.
     */
    public function book(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_uuid' => ['required', 'string'],
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.guests' => ['required', 'array', 'min:1'],
            'rooms.*.guests.*.civility' => ['required', 'string'],
            'rooms.*.guests.*.first_name' => ['required', 'string'],
            'rooms.*.guests.*.last_name' => ['required', 'string'],
            'customer' => ['required', 'array'],
            'customer.first_name' => ['required', 'string'],
            'customer.last_name' => ['required', 'string'],
            'customer.email' => ['nullable', 'email'],
            'customer.phone' => ['nullable', 'string'],
            'recommandations' => ['nullable', 'string'],
        ]);

        $cached = Cache::get("hotel_booking_{$validated['booking_uuid']}");

        if (! $cached) {
            return $this->error('Selected hotel offer expired. Please search again.', 410);
        }

        $hotelProvider = $this->providerManager->activeProvider();

        if (! $hotelProvider instanceof TenantHotelProvider) {
            return $this->error('Hotel provider is not configured.', 400);
        }

        try {
            $selectedOffer = $cached['selected_offer'];
            $search = $cached['search'];

            $bookingPayload = [
                'language' => str_replace('-', '_', (string) ($search['language'] ?? 'fr_FR')),
                'recommandations' => (string) ($validated['recommandations'] ?? ''),
                'searchCode' => (string) ($selectedOffer['search_code'] ?? ''),
                'tokenForBook' => (string) data_get($cached, 'check_rate.token_for_book', ''),
                'rooms' => $validated['rooms'],
                'payment' => [
                    'card' => '',
                    'ccv' => '',
                    'expire' => '',
                ],
                'customer' => $validated['customer'],
            ];

            $providerBooking = $this->providerManager->provider()->book($bookingPayload);

            return $this->success([
                'booking' => $providerBooking,
            ], 'Hotel booked successfully. Order will be created by the staff.', 201);
        } catch (Throwable $e) {
            report($e);

            return $this->error($e instanceof HotelApiException ? $e->getMessage() : 'Unable to complete hotel booking.', 422);
        }
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
