<?php

use App\Models\Tenant;
use App\Models\Tenant\TenantHotelProvider;
use App\Models\User;
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
                ['uid' => 'city_1', 'label' => 'Paris, France', 'subLabel' => 'Ile-de-France'],
                ['uid' => 'city_2', 'label' => 'Istanbul, Türkiye', 'subLabel' => 'Marmara'],
            ],
        ]),
    ]);

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/hotels/autocomplete?q=par');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data.destinations');
});

test('hotel search returns availability', function () {
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
            'city_id' => 'city_1',
            'check_in' => '2026-07-01',
            'check_out' => '2026-07-05',
            'rooms' => 1,
            'adults' => 2,
            'children' => 0,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['uuid', 'hotels']]);
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
        ->getJson($state['apiUrl'].'/hotels/details?hotel_id=45&city_id=city_1');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.hotel.response.hotel.name', 'Hotel Paris');
});

test('hotel search validates required fields', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/hotels/search', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['city_id', 'check_in', 'check_out', 'rooms', 'adults']);
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
