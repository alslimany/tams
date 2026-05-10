<?php

use App\Models\Tenant;
use App\Models\Tenant\AgencySetting;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Support\Facades\Cache;
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

    $providerWallet = $state['provider']->getOrCreateCurrencyWallet('LYD');
    $providerWallet->depositFloat(1000, ['type' => 'seed_provider_balance']);

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
                'passport_number' => 'A1234567',
                'passport_expiry' => '2030-01-01',
                'passport_issue_country' => 'LBY',
                'nationality' => 'LBY',
            ],
        ],
        'extras' => [
            'selected_services' => [],
            'seats' => [],
        ],
    ]);

    tenancy()->initialize($state['tenant']);

    expect($response->status())->toBe(302);
    expect(session('error'))->toBeNull();
    expect(session('errors')?->all() ?? [])->toBe([]);

    $order = Order::query()->first();

    expect($order)->not->toBeNull();
    expect(OrderItem::query()->count())->toBe(1);

    $response->assertRedirect(route('tickets.completed', ['booking' => $order->id], false));

    $orderItem = OrderItem::query()->first();

    expect($orderItem->provider_reference)->toBe('ABC123')
        ->and(data_get($orderItem->item_details, 'airline_code'))->toBe('YI')
        ->and($orderItem->status)->toBe('confirmed')
        ->and((float) $orderItem->paid)->toBe(500.0)
        ->and((float) $orderItem->remaining)->toBe(0.0)
        ->and($order->status)->toBe('confirmed')
        ->and((float) $order->amount_paid)->toBe(500.0)
        ->and($orderItem->wallet_transaction_id)->not->toBeNull();

    $providerWallet->refresh();

    $walletTransaction = WalletTransaction::query()
        ->where('uuid', (string) $orderItem->wallet_transaction_id)
        ->first();

    expect(round((float) $providerWallet->balanceFloat, 2))->toBe(500.0)
        ->and($walletTransaction)->not->toBeNull()
        ->and((int) $walletTransaction->wallet_id)->toBe((int) $providerWallet->id)
        ->and(data_get($walletTransaction->meta, 'type'))->toBe('provider_issuance_cost');
});

test('flight booking is rejected before provider booking when provider wallet is insufficient', function () {
    global $state;

    $state['provider_mock']->shouldReceive('createBooking')->never();

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->post($baseUrl.route('flights.store', [], false), [
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $state['provider']->id,
        'reservation_type' => 'NN',
        'flight' => [
            'pricing' => ['total' => 500, 'currency' => 'LYD'],
            'segments' => [[
                'flight_number' => 'YI123',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'IST',
                'departure_time' => now()->addDays(2)->toDateTimeString(),
                'arrival_time' => now()->addDays(2)->addHours(2)->toDateTimeString(),
            ]],
        ],
        'customer' => [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '123456789',
        ],
        'passengers' => [[
            'type' => 'adult',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'dob' => '1990-01-01',
            'gender' => 'M',
        ]],
        'extras' => [
            'selected_services' => [],
            'seats' => [],
        ],
    ]);

    tenancy()->initialize($state['tenant']);

    $response->assertRedirect()->assertSessionHas('error');

    expect(Order::query()->exists())->toBeFalse();
});

test('flight booking uses selected source provider wallet for issuance transactions', function () {
    global $state;

    $sourceAgency = Tenant::create([
        'id' => 'flight-source-'.Str::random(4),
        'company_name' => 'Flight Source Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    tenancy()->end();
    tenancy()->initialize($sourceAgency);
    $sourceProvider = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Yemenia Source',
        'account_name' => 'Source Provider',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);
    $sourceWallet = $sourceProvider->getOrCreateCurrencyWallet('LYD');
    $sourceWallet->depositFloat(1000, ['type' => 'seed_source_provider_balance']);
    tenancy()->end();
    tenancy()->initialize($state['tenant']);
    $state['user']->getOrCreateCurrencyWallet('LYD')->depositFloat(1000, ['type' => 'seed_merchant_balance']);
    AgencySetting::current()->update([
        'force_use_default_agency' => true,
        'can_use_own_airline_credentials' => false,
        'default_agency_tenant_id' => $sourceAgency->id,
    ]);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;
    $response = $this->post($baseUrl.route('flights.store', [], false), [
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $state['provider']->id,
        'provider_selector' => "default_agency:{$sourceAgency->id}:{$sourceProvider->id}",
        'provider_source_type' => 'default_agency',
        'source_agency_tenant_id' => $sourceAgency->id,
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $sourceProvider->id,
        'reservation_type' => 'NN',
        'flight' => [
            'pricing' => ['total' => 500, 'currency' => 'LYD'],
            'segments' => [[
                'flight_number' => 'YI123',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'IST',
                'departure_time' => now()->addDays(2)->toDateTimeString(),
                'arrival_time' => now()->addDays(2)->addHours(2)->toDateTimeString(),
            ]],
        ],
        'customer' => [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '123456789',
        ],
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
        'extras' => [
            'selected_services' => [],
            'seats' => [],
        ],
    ]);

    tenancy()->initialize($state['tenant']);

    $order = Order::query()->first();
    expect($order)->not->toBeNull();
    $response->assertRedirect(route('tickets.completed', ['booking' => $order->id], false));

    $orderItem = OrderItem::query()->first();

    expect($orderItem)->not->toBeNull()
        ->and(data_get($orderItem->item_details, 'provider_selector'))->toBe("default_agency:{$sourceAgency->id}:{$sourceProvider->id}")
        ->and(data_get($orderItem->item_details, 'provider_source_type'))->toBe('default_agency')
        ->and(data_get($orderItem->item_details, 'source_agency_tenant_id'))->toBe($sourceAgency->id)
        ->and(data_get($orderItem->item_details, 'source_provider_id'))->toBe($sourceProvider->id)
        ->and((string) $orderItem->wallet_transaction_id)->not->toBe('')
        ->and((string) data_get($orderItem->item_details, 'provider_wallet_transaction_id'))->not->toBe('');

    tenancy()->end();
    tenancy()->initialize($sourceAgency);
    $sourceWallet->refresh();
    $walletTransaction = WalletTransaction::query()
        ->where('uuid', (string) data_get($orderItem->item_details, 'provider_wallet_transaction_id'))
        ->first();

    expect(round((float) $sourceWallet->balanceFloat, 2))->toBe(500.0)
        ->and($walletTransaction)->not->toBeNull()
        ->and((int) $walletTransaction->wallet_id)->toBe((int) $sourceWallet->id)
        ->and(data_get($walletTransaction->meta, 'provider_selector'))->toBe("default_agency:{$sourceAgency->id}:{$sourceProvider->id}")
        ->and(data_get($walletTransaction->meta, 'source_agency_tenant_id'))->toBe($sourceAgency->id);
    tenancy()->end();
    tenancy()->initialize($state['tenant']);
});

test('flight booking is rejected before provider booking when selected source provider wallet is insufficient', function () {
    global $state;

    $sourceAgency = Tenant::create([
        'id' => 'flight-empty-source-'.Str::random(4),
        'company_name' => 'Empty Source Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    tenancy()->end();
    tenancy()->initialize($sourceAgency);
    $sourceProvider = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Yemenia Source',
        'account_name' => 'Empty Source Provider',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);
    tenancy()->end();
    tenancy()->initialize($state['tenant']);
    $state['user']->getOrCreateCurrencyWallet('LYD')->depositFloat(1000, ['type' => 'seed_merchant_balance']);
    AgencySetting::current()->update([
        'force_use_default_agency' => true,
        'can_use_own_airline_credentials' => false,
        'default_agency_tenant_id' => $sourceAgency->id,
    ]);

    $state['provider_mock']->shouldReceive('createBooking')->never();

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;
    $response = $this->post($baseUrl.route('flights.store', [], false), [
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $state['provider']->id,
        'provider_selector' => "default_agency:{$sourceAgency->id}:{$sourceProvider->id}",
        'provider_source_type' => 'default_agency',
        'source_agency_tenant_id' => $sourceAgency->id,
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $sourceProvider->id,
        'reservation_type' => 'NN',
        'flight' => [
            'pricing' => ['total' => 500, 'currency' => 'LYD'],
            'segments' => [[
                'flight_number' => 'YI123',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'IST',
                'departure_time' => now()->addDays(2)->toDateTimeString(),
                'arrival_time' => now()->addDays(2)->addHours(2)->toDateTimeString(),
            ]],
        ],
        'customer' => [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '123456789',
        ],
        'passengers' => [[
            'type' => 'adult',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'dob' => '1990-01-01',
            'gender' => 'M',
        ]],
        'extras' => [
            'selected_services' => [],
            'seats' => [],
        ],
    ]);

    tenancy()->initialize($state['tenant']);

    $response->assertRedirect()->assertSessionHas('error');

    expect(Order::query()->exists())->toBeFalse();
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

test('open reservation availability prefers provider selector and keeps provider id fallback', function () {
    global $state;

    $secondProvider = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => '8U',
        'airline_name' => 'Afriqiyah',
        'account_name' => 'Second',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    $state['provider_mock']->shouldReceive('canBookOpenReservation')
        ->twice()
        ->andReturn(true);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->postJson($baseUrl.route('flights.open-reservation-availability', [], false), [
        'provider_id' => $state['provider']->id,
        'provider_selector' => "own:{$secondProvider->id}",
        'flight' => [
            'flight_number' => '8U123',
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

    $this->postJson($baseUrl.route('flights.open-reservation-availability', [], false), [
        'provider_id' => $state['provider']->id,
        'provider_selector' => 'own:999999',
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

test('seatmap prefers provider selector and keeps provider id fallback', function () {
    global $state;

    $secondProvider = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => '8U',
        'airline_name' => 'Afriqiyah',
        'account_name' => 'Second',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    $state['provider_mock']->shouldReceive('getSeatMap')
        ->twice()
        ->andReturn(['seats' => [['number' => '1A', 'available' => true]]]);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->postJson($baseUrl.route('flights.seatmap', [], false), [
        'provider_id' => $state['provider']->id,
        'provider_selector' => "own:{$secondProvider->id}",
        'flight_number' => '8U123',
        'date' => now()->addDays(2)->toDateString(),
    ])->assertSuccessful()->assertJsonPath('seats.0.number', '1A');

    $this->postJson($baseUrl.route('flights.seatmap', [], false), [
        'provider_id' => $state['provider']->id,
        'provider_selector' => 'own:999999',
        'flight_number' => 'YI123',
        'date' => now()->addDays(2)->toDateString(),
    ])->assertSuccessful()->assertJsonPath('seats.0.number', '1A');
});

test('selected offer is cached and passengers page is reachable by uuid url', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;
    $uuid = Str::uuid()->toString();

    Cache::put("flight_search_{$uuid}", [
        'origin' => 'MJI',
        'destination' => 'IST',
        'date' => now()->addDays(2)->toDateString(),
        'adults' => 1,
        'children' => 0,
        'infants' => 0,
        'is_return' => false,
    ], now()->addMinutes(30));

    $selectResponse = $this->post($baseUrl.route('flights.select', [], false), [
        'uuid' => $uuid,
        'provider_id' => $state['provider']->id,
        'provider_selector' => 'agency_network:123',
        'provider_source_type' => 'agency_network',
        'source_agency_tenant_id' => 'source-agency-1',
        'merchant_tenant_id' => $state['tenant']->id,
        'network_membership_id' => 456,
        'provider_allocation_id' => 123,
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $state['provider']->id,
        'reservation_type' => 'NN',
        'flight' => [
            'pricing' => ['total' => 500, 'currency' => 'LYD'],
            'segments' => [[
                'flight_number' => 'YI123',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'IST',
                'departure_time' => now()->addDays(2)->toDateTimeString(),
                'arrival_time' => now()->addDays(2)->addHours(2)->toDateTimeString(),
            ]],
        ],
    ]);

    $selectResponse->assertRedirect(route('flights.passengers', ['uuid' => $uuid], false));

    $selectedOffer = Cache::get("flight_search_{$uuid}")['selected_offer'] ?? [];

    expect($selectedOffer)
        ->provider_selector->toBe('agency_network:123')
        ->provider_source_type->toBe('agency_network')
        ->source_agency_tenant_id->toBe('source-agency-1')
        ->merchant_tenant_id->toBe($state['tenant']->id)
        ->network_membership_id->toBe(456)
        ->provider_allocation_id->toBe(123)
        ->source_provider_model->toBe(TenantProvider::class)
        ->source_provider_id->toBe($state['provider']->id);

    $this->get($baseUrl.route('flights.passengers', ['uuid' => $uuid], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Bookings/PassengerInfo')
            ->where('uuid', $uuid)
            ->where('provider_id', $state['provider']->id)
            ->where('reservation_type', 'NN')
        );
});

test('selected offer uses provider selector for ancillary catalog and falls back to provider id', function () {
    global $state;

    $secondProvider = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => '8U',
        'airline_name' => 'Afriqiyah',
        'account_name' => 'Second',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;
    $selectorUuid = Str::uuid()->toString();
    $fallbackUuid = Str::uuid()->toString();

    foreach ([$selectorUuid, $fallbackUuid] as $uuid) {
        Cache::put("flight_search_{$uuid}", [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => now()->addDays(2)->toDateString(),
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'is_return' => false,
        ], now()->addMinutes(30));
    }

    $flight = [
        'pricing' => ['total' => 500, 'currency' => 'LYD'],
        'segments' => [[
            'flight_number' => '8U123',
            'departure_airport' => 'MJI',
            'arrival_airport' => 'IST',
            'departure_time' => now()->addDays(2)->toDateTimeString(),
            'arrival_time' => now()->addDays(2)->addHours(2)->toDateTimeString(),
        ]],
    ];

    $this->post($baseUrl.route('flights.select', [], false), [
        'uuid' => $selectorUuid,
        'provider_id' => $state['provider']->id,
        'provider_selector' => "own:{$secondProvider->id}",
        'provider_source_type' => 'own',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $secondProvider->id,
        'reservation_type' => 'NN',
        'flight' => $flight,
    ])->assertRedirect(route('flights.passengers', ['uuid' => $selectorUuid], false));

    $this->post($baseUrl.route('flights.select', [], false), [
        'uuid' => $fallbackUuid,
        'provider_id' => $state['provider']->id,
        'provider_selector' => 'own:999999',
        'provider_source_type' => 'own',
        'reservation_type' => 'NN',
        'flight' => $flight,
    ])->assertRedirect(route('flights.passengers', ['uuid' => $fallbackUuid], false));

    $selectorOffer = Cache::get("flight_search_{$selectorUuid}")['selected_offer'] ?? [];
    $fallbackOffer = Cache::get("flight_search_{$fallbackUuid}")['selected_offer'] ?? [];

    expect($selectorOffer)
        ->provider_id->toBe($state['provider']->id)
        ->provider_selector->toBe("own:{$secondProvider->id}")
        ->source_provider_id->toBe($secondProvider->id)
        ->and($fallbackOffer)
        ->provider_id->toBe($state['provider']->id)
        ->provider_selector->toBe('own:999999');
});

test('passengers page resolves cached provider selector and keeps provider id fallback', function () {
    global $state;

    $secondProvider = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => '8U',
        'airline_name' => 'Afriqiyah',
        'account_name' => 'Second',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;
    $selectorUuid = Str::uuid()->toString();
    $fallbackUuid = Str::uuid()->toString();
    $flight = [
        'pricing' => ['total' => 500, 'currency' => 'LYD'],
        'segments' => [[
            'flight_number' => '8U123',
            'departure_airport' => 'MJI',
            'arrival_airport' => 'IST',
            'departure_time' => now()->addDays(2)->toDateTimeString(),
            'arrival_time' => now()->addDays(2)->addHours(2)->toDateTimeString(),
        ]],
    ];

    Cache::put("flight_search_{$selectorUuid}", [
        'origin' => 'MJI',
        'destination' => 'IST',
        'date' => now()->addDays(2)->toDateString(),
        'adults' => 1,
        'children' => 0,
        'infants' => 0,
        'is_return' => false,
        'selected_offer' => [
            'provider_id' => $state['provider']->id,
            'provider_selector' => "own:{$secondProvider->id}",
            'flight' => $flight,
            'reservation_type' => 'NN',
            'is_round_trip' => false,
        ],
    ], now()->addMinutes(30));

    Cache::put("flight_search_{$fallbackUuid}", [
        'origin' => 'MJI',
        'destination' => 'IST',
        'date' => now()->addDays(2)->toDateString(),
        'adults' => 1,
        'children' => 0,
        'infants' => 0,
        'is_return' => false,
        'selected_offer' => [
            'provider_id' => $state['provider']->id,
            'provider_selector' => 'own:999999',
            'flight' => $flight,
            'reservation_type' => 'NN',
            'is_round_trip' => false,
        ],
    ], now()->addMinutes(30));

    $this->get($baseUrl.route('flights.passengers', ['uuid' => $selectorUuid], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Bookings/PassengerInfo')
            ->where('uuid', $selectorUuid)
            ->where('provider_id', $state['provider']->id)
            ->where('reservation_type', 'NN')
        );

    $this->get($baseUrl.route('flights.passengers', ['uuid' => $fallbackUuid], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Bookings/PassengerInfo')
            ->where('uuid', $fallbackUuid)
            ->where('provider_id', $state['provider']->id)
            ->where('reservation_type', 'NN')
        );
});
