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
        'id' => 'flight-order-'.Str::random(4),
        'company_name' => 'Flight Order Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    $state['tenant'] = $tenant;

    tenancy()->initialize($tenant);

    $state['user'] = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $state['provider'] = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'Default',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('getPricing')->andReturn('<FareQuote />');
    $providerMock->shouldReceive('createBooking')->andReturn('<PNR RLOC="ABC123"></PNR>');
    $providerMock->shouldReceive('getAncillaryCatalog')->andReturn([]);
    $state['provider_mock'] = $providerMock;

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

test('flight pages are available and booking data is stored in orders', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->get($baseUrl.route('flights.index', [], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Tenant/Bookings/Search'));

    $response = $this->post($baseUrl.route('flights.store', [], false), [
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $state['provider']->id,
        'reservation_type' => 'NN',
        'flight' => [
            'pricing' => ['total' => 500, 'currency' => 'LYD'],
            'segments' => [
                [
                    'flight_number' => 'YI123',
                    'departure_airport' => 'MJI',
                    'arrival_airport' => 'IST',
                    'departure_time' => now()->addDays(2)->toDateTimeString(),
                    'arrival_time' => now()->addDays(2)->addHours(2)->toDateTimeString(),
                ],
            ],
        ],
        'customer' => [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '123456789',
        ],
        'passengers' => [
            [
                'type' => 'adult',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'dob' => '1990-01-01',
                'gender' => 'M',
            ],
        ],
        'extras' => [
            'selected_services' => [],
            'seats' => [],
        ],
    ]);

    $order = Order::query()->first();

    expect($order)->not->toBeNull();
    expect(OrderItem::query()->count())->toBe(1);

    $response->assertRedirect(route('flights.show', ['booking' => $order->id], false));

    $orderItem = OrderItem::query()->first();

    expect($orderItem->provider_reference)->toBe('ABC123')
        ->and(data_get($orderItem->item_details, 'airline_code'))->toBe('YI');
});

test('flight search route accepts get requests for results date switching', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get($baseUrl.route('flights.search', [
        'origin' => 'MJI',
        'destination' => 'IST',
        'date' => '2026-04-30',
        'adults' => 1,
        'children' => 0,
        'infants' => 0,
        'is_return' => false,
    ], false));

    $response->assertRedirect();

    $location = $response->headers->get('Location');

    expect($location)->not->toBeNull()
        ->and($location)->toContain('/flights/results/');
});

test('open reservation availability endpoint returns provider eligibility', function () {
    global $state;

    $state['provider_mock']->shouldReceive('canBookOpenReservation')
        ->once()
        ->andReturn(true);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->postJson($baseUrl.route('flights.open-reservation-availability', [], false), [
        'provider_id' => $state['provider']->id,
        'flight' => [
            'flight_number' => 'YI123',
            'departure_airport' => 'MJI',
            'arrival_airport' => 'IST',
            'departure_time' => now()->addDays(2)->toDateTimeString(),
            'pricing' => [
                'class_code' => 'Y',
            ],
        ],
    ])->assertSuccessful()->assertJson([
        'allowed' => true,
    ]);
});
