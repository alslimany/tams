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
        'id' => 'rt-outbound-'.Str::random(4),
        'company_name' => 'Round Trip Outbound Agency',
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
        'account_name' => 'A1',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    Cache::put('flight_search_rt-outbound', [
        'origin' => 'MJI',
        'destination' => 'BEN',
        'date' => '2026-04-20',
        'return_date' => '2026-04-30',
        'adults' => 1,
        'children' => 0,
        'infants' => 0,
        'is_return' => true,
    ], now()->addMinutes(30));

    $providerMock = \Mockery::mock(AirlineProviderInterface::class);
    $providerMock->shouldReceive('searchAvailability')
        ->once()
        ->withArgs(function (array $params): bool {
            return ($params['origin'] ?? null) === 'MJI'
                && ($params['destination'] ?? null) === 'BEN'
                && ($params['date'] ?? null) === '2026-04-20'
                && ($params['is_return'] ?? true) === false
                && ! array_key_exists('return_date', $params)
                && ($params['qty'] ?? null) === 1;
        })
        ->andReturn([
            [
                'id' => 'out-yi-1',
                'airline_code' => 'YI',
                'airline_name' => 'Oya',
                'flight_number' => '0500',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'BEN',
                'departure_time' => '2026-04-20 10:00:00',
                'arrival_time' => '2026-04-20 11:00:00',
                'segments' => [
                    [
                        'flight_number' => '0500',
                        'class' => 'Y',
                        'departure_airport' => 'MJI',
                        'arrival_airport' => 'BEN',
                        'departure_time' => '2026-04-20 10:00:00',
                    ],
                ],
                'pricing' => ['total' => 200, 'currency' => 'LYD'],
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

test('round trip outbound fetch uses one-way availability search params', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->postJson($baseUrl.route('flights.fetch-flights', [], false), [
        'uuid' => 'rt-outbound',
        'provider_id' => $state['provider']->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('provider_id', $state['provider']->id)
        ->assertJsonCount(1, 'flights');
});
