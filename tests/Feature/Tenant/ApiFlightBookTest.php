<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
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
    \Mockery::close();
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

test('successful book creates order and item visible in backend', function () {
    global $state;

    // Seed the provider wallet so own_credentials financial flow can proceed
    tenancy()->initialize($state['tenant']);
    $provider = TenantProvider::query()->first();
    $provider->getOrCreateCurrencyWallet('LYD')->depositFloat(1000, ['type' => 'seed_provider_balance']);

    // Mock ProviderFactory so no real Videcom call is made
    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('getPricing')->once()->andReturn('<FareQuote />');
    $providerMock->shouldReceive('createBooking')->once()->andReturn('<PNR RLOC="ABC123"></PNR>');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    // Step 1: select the flight into the session
    $this->withToken($state['token'])
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
                'pricing' => ['total' => 450, 'currency' => 'LYD', 'base' => 400, 'tax' => 50],
            ],
            'reservation_type' => 'NN',
        ])
        ->assertOk();

    // Step 2: book with passengers
    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/book', [
            'uuid' => $state['uuid'],
            'passengers' => [[
                'type' => 'adult',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'dob' => '1990-01-01',
                'gender' => 'M',
                'passport_number' => 'A1234567',
                'passport_expiry' => '2030-01-01',
                'passport_issue_country' => 'LBY',
                'nationality' => 'LBY',
            ]],
            'customer' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'phone' => '0911234567',
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.items.0.provider_reference', 'ABC123');

    // Re-initialize tenancy to query the tenant DB directly
    tenancy()->initialize($state['tenant']);

    // Assert order exists with correct fields
    $order = Order::query()->first();

    expect($order)->not->toBeNull()
        ->and($order->status)->toBe('confirmed')
        ->and($order->payment_reference)->toBe('ABC123')
        ->and((float) $order->amount_paid)->toBe(450.0)
        ->and((float) $order->grand_total)->toBe(450.0)
        ->and($order->currency)->toBe('LYD')
        ->and($order->issued_at)->not->toBeNull();

    // Assert order item has correct fields including provider ID fields
    $item = OrderItem::query()->first();

    expect($item)->not->toBeNull()
        ->and($item->provider_reference)->toBe('ABC123')
        ->and($item->type)->toBe('flight')
        ->and($item->product_type)->toBe('ticket')
        ->and($item->status)->toBe('confirmed')
        ->and(data_get($item->item_details, 'airline_code'))->toBe('YI')
        ->and(data_get($item->item_details, 'pnr'))->toBe('ABC123')
        ->and((float) $item->paid)->toBe(450.0)
        ->and((float) $item->remaining)->toBe(0.0);

    // Assert the order appears in the API orders list
    $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/orders')
        ->assertOk()
        ->assertJsonPath('data.data.0.payment_reference', 'ABC123')
        ->assertJsonPath('data.data.0.status', 'confirmed');
});
