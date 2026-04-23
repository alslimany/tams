<?php

use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Airline\AirlineProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'ret-skip-'.Str::random(4),
        'company_name' => 'Skip Unsupported Providers Agency',
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

    $state['validProvider'] = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'A1',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    TenantProvider::create([
        'provider_type' => '',
        'airline_code' => 'XX',
        'airline_name' => 'Broken Provider',
        'account_name' => 'B1',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    Cache::put('flight_search_rt-skip', [
        'origin' => 'MJI',
        'destination' => 'IST',
        'date' => '2026-05-01',
        'return_date' => '2026-05-10',
        'adults' => 1,
        'children' => 0,
        'infants' => 0,
        'is_return' => true,
    ], now()->addMinutes(30));

    $providerMock = \Mockery::mock(AirlineProviderInterface::class);
    $providerMock->shouldReceive('searchReturnLeg')->once()->andReturn([
        [
            'id' => 'ret-yi-1',
            'airline_code' => 'YI',
            'airline_name' => 'Oya',
            'flight_number' => '0501',
            'departure_airport' => 'IST',
            'arrival_airport' => 'MJI',
            'departure_time' => '2026-05-10 18:00:00',
            'arrival_time' => '2026-05-10 20:00:00',
            'segments' => [
                [
                    'flight_number' => '0501',
                    'class' => 'Y',
                    'departure_airport' => 'IST',
                    'arrival_airport' => 'MJI',
                    'departure_time' => '2026-05-10 18:00:00',
                ],
            ],
            'pricing' => ['total' => 180, 'currency' => 'LYD'],
        ],
    ]);
    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->once()
        ->andReturn($providerMock);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

test('return options ignores active providers with unsupported type', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->postJson($baseUrl.route('flights.return-options', [], false), [
        'uuid' => 'rt-skip',
        'outbound_provider_id' => $state['validProvider']->id,
        'outbound_flight' => [
            'airline_code' => 'XX',
            'airline_name' => 'Other',
            'flight_number' => '9000',
            'departure_airport' => 'MJI',
            'arrival_airport' => 'IST',
            'departure_time' => '2026-05-01 10:00:00',
            'segments' => [
                [
                    'flight_number' => '9000',
                    'class' => 'Y',
                    'departure_airport' => 'MJI',
                    'arrival_airport' => 'IST',
                    'departure_time' => '2026-05-01 10:00:00',
                ],
            ],
            'pricing' => ['total' => 200, 'currency' => 'LYD'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'return_options')
        ->assertJsonPath('return_options.0.airline_code', 'YI')
        ->assertJsonPath('return_options.0.pricing_method', 'oneway')
        ->assertJsonPath('return_options.0.pricing.total', 180);
});
