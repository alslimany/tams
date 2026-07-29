<?php

use App\Models\Tenant;
use App\Models\Tenant\TenantHotelProvider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-hotel-'.Str::random(4),
        'company_name' => 'API Hotel Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $user = User::factory()->create([
        'email' => 'agent@example.com',
        'password' => 'Secret123!',
        'role' => 'agent',
        'is_active' => true,
    ]);

    TenantHotelProvider::query()->create([
        'provider_type' => '3t',
        'name' => '3T Hotels',
        'credentials' => [
            'base_url' => 'https://btob.3t.tn',
            'api_key' => 'test-api-key',
            'login' => 'test-login',
            'password' => 'test-password',
        ],
        'is_active' => true,
        'commission_hotel' => 10,
        'currency' => 'LYD',
    ]);

    $token = $user->createToken('Test Device')->plainTextToken;

    $state['tenant'] = $tenant;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
    $state['token'] = $token;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('hotel autocomplete returns destinations', function () {
    global $state;

    Http::fake([
        'https://btob.3t.tn/hotels-api?method=autocomplete' => Http::response([
            'method' => 'autocomplete',
            'response' => [
                ['cityId' => 215, 'label' => 'Paris, France', 'subLabel' => 'Ile-de-France'],
                ['cityId' => 88, 'label' => 'Istanbul, Türkiye', 'subLabel' => 'Marmara'],
            ],
        ]),
    ]);

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/hotels/autocomplete?q=par');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data.destinations')
        ->assertJsonPath('data.destinations.0.city_id', 215)
        ->assertJsonPath('data.destinations.0.code', '215');
});

test('hotel search sends 3T availability payload with city and occupancies', function () {
    global $state;

    Http::fake([
        'https://btob.3t.tn/hotels-api?method=availability' => Http::response([
            'method' => 'availability',
            'response' => [[
                'source' => 129,
                'hotel' => [
                    'hotelId' => 45,
                    'hotelUid' => 'hotel_45',
                    'name' => 'Hotel Paris',
                    'rating' => 4,
                ],
                'rooms' => [[
                    'rateKey' => 'rate_123',
                    'price' => 150,
                    'currency' => 'EUR',
                    'boardName' => 'Bed & Breakfast',
                ]],
            ]],
            'search_code' => 'SRCH-001',
            'hotels_count' => 1,
            'pages' => 1,
        ]),
    ]);

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/hotels/search', [
            'city' => 'Paris, France',
            'city_id' => 215,
            'check_in' => '2026-08-01',
            'check_out' => '2026-08-05',
            'rooms' => [
                ['adult' => 2, 'children' => [5]],
            ],
            'page' => 1,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['uuid', 'hotels']]);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'method=availability')) {
            return false;
        }

        $payload = $request->data();

        return $payload['checkIn'] === '2026-08-01'
            && $payload['checkOut'] === '2026-08-05'
            && $payload['city'] === 'Paris'
            && $payload['cityId'] === 215
            && $payload['occupancies']['1']['adult'] === '2'
            && $payload['occupancies']['1']['child']['value'] === 1
            && $payload['occupancies']['1']['child']['age'] === '5'
            && $payload['onlyAvailableHotels'] === true
            && $payload['channel'] === 'b2b'
            && $payload['language'] === 'fr_FR';
    });
});

test('hotel search accepts flat rooms shortcut without children', function () {
    global $state;

    Http::fake([
        'https://btob.3t.tn/hotels-api?method=availability' => Http::response([
            'method' => 'availability',
            'response' => [],
            'search_code' => 'SRCH-002',
            'hotels_count' => 0,
            'pages' => 1,
        ]),
    ]);

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/hotels/search', [
            'city' => 'Tunis',
            'city_id' => 12,
            'check_in' => '2026-08-01',
            'check_out' => '2026-08-03',
            'rooms' => 1,
            'adults' => 2,
            'children' => 0,
        ]);

    $response->assertOk();

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'method=availability')) {
            return false;
        }

        $payload = $request->data();

        return $payload['cityId'] === 12
            && $payload['occupancies']['1']['adult'] === '2'
            && $payload['occupancies']['1']['child']['value'] === 0;
    });
});

test('hotel search rejects flat children without ages', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/hotels/search', [
            'city' => 'Tunis',
            'city_id' => 12,
            'check_in' => '2026-08-01',
            'check_out' => '2026-08-03',
            'rooms' => 1,
            'adults' => 2,
            'children' => 1,
        ]);

    $response->assertStatus(422)
        ->assertJsonFragment(['message' => 'Each child requires an age. Send rooms[].children as ages, or children_ages matching children count.']);
});

test('hotel details returns hotel info', function () {
    global $state;

    Http::fake([
        'https://btob.3t.tn/hotels-api?method=hotelDetails' => Http::response([
            'method' => 'hotelDetails',
            'response' => [
                'hotel' => [
                    'name' => 'Hotel Paris',
                    'description' => 'A beautiful hotel in Paris.',
                    'images' => ['img1.jpg'],
                ],
            ],
        ]),
    ]);

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/hotels/details?hotel_id=45&city_id=215');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.hotel.response.hotel.name', 'Hotel Paris');
});

test('hotel search validates required fields', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/hotels/search', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['city', 'city_id', 'check_in', 'check_out', 'rooms']);
});

test('hotel select with expired search returns 410', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/hotels/select', [
            'search_uuid' => 'expired-uuid',
            'rate_key' => 'rate_123',
        ]);

    $response->assertStatus(410);
});

test('hotel book maps guests to provider paxes payload', function () {
    global $state;

    Http::fake([
        'https://btob.3t.tn/hotels-api?method=book' => Http::response([
            'method' => 'book',
            'error' => false,
            'response' => ['bookingId' => 'BK-001'],
        ]),
    ]);

    $bookingUuid = (string) Str::uuid();

    Cache::put("hotel_booking_{$bookingUuid}", [
        'search' => [
            'city' => 'Djerba',
            'city_id' => 12,
            'language' => 'fr-FR',
        ],
        'selected_offer' => [
            'rate_key' => 'RATE-ABC',
            'search_code' => 'SRCH-9',
        ],
        'check_rate' => [
            'token_for_book' => 'TOKEN-BOOK',
        ],
    ], now()->addMinutes(10));

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/hotels/book', [
            'booking_uuid' => $bookingUuid,
            'rooms' => [[
                'guests' => [[
                    'civility' => 'Mr',
                    'first_name' => 'Rayan',
                    'last_name' => 'Fathi',
                ]],
            ]],
            'customer' => [
                'first_name' => 'rayan',
                'last_name' => 'Fathi',
                'email' => 'a.rayan@median.ly',
                'phone' => '+218943215277',
                'country' => 'Tunisie',
                'city' => 'Djerba',
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'method=book')) {
            return false;
        }

        $payload = $request->data();

        return ($payload['tokenForBook'] ?? null) === 'TOKEN-BOOK'
            && ($payload['language'] ?? null) === 'fr-FR'
            && ($payload['rooms'][0]['ratekey'] ?? null) === 'RATE-ABC'
            && ($payload['rooms'][0]['paxes'][0]['firstName'] ?? null) === 'Rayan'
            && ($payload['rooms'][0]['paxes'][0]['lastName'] ?? null) === 'Fathi'
            && ($payload['customer']['city'] ?? null) === 'Djerba'
            && ($payload['customer']['country'] ?? null) === 'Tunisie'
            && ! isset($payload['rooms'][0]['guests']);
    });
});

test('hotel book validates customer city and country', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/hotels/book', [
            'booking_uuid' => '0905241a-99d3-444c-bad6-086ce9a1759a',
            'rooms' => [[
                'guests' => [[
                    'civility' => 'Mr',
                    'first_name' => 'Rayan',
                    'last_name' => 'Fathi',
                ]],
            ]],
            'customer' => [
                'first_name' => 'Rayan',
                'last_name' => 'Fathi',
                'email' => 'a.rayan@median.ly',
                'phone' => '+218943215277',
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['customer.city', 'customer.country']);
});

test('hotel book with expired booking returns 410', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/hotels/book', [
            'booking_uuid' => 'expired-uuid',
            'rooms' => [[
                'guests' => [[
                    'civility' => 'Mr',
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                ]],
            ]],
            'customer' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'country' => 'Libya',
                'city' => 'Tripoli',
            ],
        ]);

    $response->assertStatus(410);
});

test('unauthenticated hotel requests are rejected', function () {
    global $state;

    $this->getJson($state['apiUrl'].'/hotels/autocomplete?q=par')->assertUnauthorized();
    $this->postJson($state['apiUrl'].'/hotels/search', [])->assertUnauthorized();
    $this->getJson($state['apiUrl'].'/hotels/details?hotel_id=1')->assertUnauthorized();
    $this->postJson($state['apiUrl'].'/hotels/select', [])->assertUnauthorized();
    $this->postJson($state['apiUrl'].'/hotels/book', [])->assertUnauthorized();
});
