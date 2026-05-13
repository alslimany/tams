<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Controller;
use App\Models\Tenant\TenantHotelProvider;
use App\Services\Hotels\HotelApiException;
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
                'destinations' => $items,
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
            'city_id' => ['required', 'string'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'rooms' => ['required', 'integer', 'min:1', 'max:5'],
            'adults' => ['required', 'integer', 'min:1', 'max:10'],
            'children' => ['nullable', 'integer', 'min:0', 'max:5'],
            'language' => ['nullable', 'string', 'in:fr-FR,en-US,ar-AR'],
            'source' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $uuid = (string) Str::uuid();
        Cache::put("hotel_search_{$uuid}", $validated, now()->addMinutes(60));

        $page = max(1, (int) ($validated['page'] ?? 1));

        try {
            $providerSource = $this->providerManager->activeProviderSource();
            $payload = $this->providerManager->provider()->availability([
                'cityId' => (string) $validated['city_id'],
                'checkIn' => (string) $validated['check_in'],
                'checkOut' => (string) $validated['check_out'],
                'rooms' => (int) $validated['rooms'],
                'adults' => (int) $validated['adults'],
                'children' => (int) ($validated['children'] ?? 0),
                'language' => (string) ($validated['language'] ?? 'fr-FR'),
                'source' => (string) ($validated['source'] ?? ''),
                'page' => $page,
            ]);

            return $this->success([
                'uuid' => $uuid,
                'hotels' => $payload['response'] ?? [],
                'search_code' => (string) ($payload['search_code'] ?: data_get($payload, 'raw.searchCode', '')),
                'pages' => (int) ($payload['pages'] ?? 1),
                'hotels_count' => (int) ($payload['hotels_count'] ?? 0),
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
                'language' => (string) ($validated['language'] ?? 'fr-FR'),
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
                'language' => (string) ($search['language'] ?? 'fr-FR'),
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
}
