<?php

use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-flightbk-'.Str::random(4),
        'company_name' => 'API Flight Book Agency',
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

    TenantProvider::query()->create([
        'name' => 'Videcom Airways',
        'airline_code' => 'YI',
        'airline_name' => 'Videcom Airways',
        'provider_type' => 'videcom',
        'credentials' => ['base_url' => 'https://example.com', 'api_key' => 'test-key'],
        'is_active' => true,
        'commission_own' => 5,
        'currency' => 'LYD',
    ]);

    $token = $user->createToken('Test Device')->plainTextToken;

    $state['tenant'] = $tenant;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
    $state['token'] = $token;

    // Create a search session for reuse
    $search = $this->withToken($token)
        ->postJson($state['apiUrl'].'/flights/search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'is_return' => false,
        ]);

    $state['uuid'] = $search->json('data.uuid');
    $state['providerId'] = $search->json('data.providers.0.id');
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('select flight caches the selected offer', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/select', [
            'uuid' => $state['uuid'],
            'provider_id' => $state['providerId'],
            'flight' => [
                'flight_number' => 'YI500',
                'class' => 'Y',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'IST',
                'departure_time' => '2026-06-15T10:00:00',
                'arrival_time' => '2026-06-15T14:00:00',
                'pricing' => [
                    'total' => 450,
                    'currency' => 'LYD',
                    'base' => 400,
                    'tax' => 50,
                ],
            ],
            'reservation_type' => 'NN',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.provider_id', $state['providerId'])
        ->assertJsonPath('data.flight.flight_number', 'YI500');
});

test('select with expired uuid returns 410', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/select', [
            'uuid' => 'expired-uuid',
            'provider_id' => $state['providerId'],
            'flight' => ['segments' => []],
        ]);

    $response->assertStatus(410);
});

test('select validates required fields', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/select', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['uuid', 'provider_id', 'flight']);
});

test('book without passengers fails validation', function () {
    global $state;

    // Select first
    $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/select', [
            'uuid' => $state['uuid'],
            'provider_id' => $state['providerId'],
            'flight' => [
                'flight_number' => 'YI500',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'IST',
                'departure_time' => '2026-06-15T10:00:00',
                'arrival_time' => '2026-06-15T14:00:00',
                'pricing' => ['total' => 450, 'currency' => 'LYD'],
            ],
        ]);

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/book', [
            'uuid' => $state['uuid'],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['passengers', 'customer']);
});

test('book with expired session returns 410', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/book', [
            'uuid' => 'expired-uuid',
            'passengers' => [[
                'type' => 'adult',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ]],
            'customer' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
            ],
        ]);

    $response->assertStatus(410);
});

test('book without prior select returns 410', function () {
    global $state;

    // Create a fresh search session without a selection
    $search = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'is_return' => false,
        ]);
    $freshUuid = $search->json('data.uuid');

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/book', [
            'uuid' => $freshUuid,
            'passengers' => [[
                'type' => 'adult',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ]],
            'customer' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
        ]);

    $response->assertStatus(410);
});
