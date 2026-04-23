<?php

use App\DTOs\Airline\RoundTripPriceResult;
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
        'id' => 'return-opt-'.Str::random(4),
        'company_name' => 'Return Options Agency',
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

    $state['providerA'] = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'A1',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    $state['providerB'] = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => '5S',
        'airline_name' => 'Global',
        'account_name' => 'B1',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    Cache::put('flight_search_rt-123', [
        'origin' => 'MJI',
        'destination' => 'IST',
        'date' => '2026-05-01',
        'return_date' => '2026-05-10',
        'adults' => 1,
        'children' => 0,
        'infants' => 0,
        'is_return' => true,
    ], now()->addMinutes(30));

    $providerYI = \Mockery::mock(AirlineProviderInterface::class);
    $providerYI->shouldReceive('searchReturnLeg')->once()->withArgs(function (array $params): bool {
        return ($params['origin'] ?? null) === 'IST'
            && ($params['destination'] ?? null) === 'MJI'
            && ($params['is_return'] ?? true) === false
            && ! array_key_exists('return_date', $params);
    })->andReturn([
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
            'pricing' => ['total' => 999, 'currency' => 'LYD'],
        ],
    ]);
    $providerYI->shouldReceive('priceRoundTrip')->once()->andReturn(new RoundTripPriceResult(150, 'LYD', 350));
    $provider5S = \Mockery::mock(AirlineProviderInterface::class);
    $provider5S->shouldReceive('searchReturnLeg')->once()->withArgs(function (array $params): bool {
        return ($params['origin'] ?? null) === 'IST'
            && ($params['destination'] ?? null) === 'MJI'
            && ($params['is_return'] ?? true) === false
            && ! array_key_exists('return_date', $params);
    })->andReturn([
        [
            'id' => 'ret-5s-1',
            'airline_code' => '5S',
            'airline_name' => 'Global',
            'flight_number' => '0755',
            'departure_airport' => 'IST',
            'arrival_airport' => 'MJI',
            'departure_time' => '2026-05-10 21:00:00',
            'arrival_time' => '2026-05-10 23:00:00',
            'segments' => [
                [
                    'flight_number' => '0755',
                    'class' => 'Y',
                    'departure_airport' => 'IST',
                    'arrival_airport' => 'MJI',
                    'departure_time' => '2026-05-10 21:00:00',
                ],
            ],
            'pricing' => ['total' => 210, 'currency' => 'LYD'],
        ],
    ]);

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturnUsing(function ($provider) use ($providerYI, $provider5S) {
            return $provider->airline_code === 'YI' ? $providerYI : $provider5S;
        });
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

test('return options use round-trip pricing for same provider and one-way pricing for others', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->postJson($baseUrl.route('flights.return-options', [], false), [
        'uuid' => 'rt-123',
        'outbound_provider_id' => $state['providerA']->id,
        'outbound_flight' => [
            'airline_code' => 'YI',
            'airline_name' => 'Oya',
            'flight_number' => '0500',
            'departure_airport' => 'MJI',
            'arrival_airport' => 'IST',
            'departure_time' => '2026-05-01 10:00:00',
            'segments' => [
                [
                    'flight_number' => '0500',
                    'class' => 'Y',
                    'departure_airport' => 'MJI',
                    'arrival_airport' => 'IST',
                    'departure_time' => '2026-05-01 10:00:00',
                ],
            ],
            'pricing' => ['total' => 200, 'currency' => 'LYD'],
        ],
        'reservation_type' => 'NN',
    ]);

    $response->assertOk();

    $options = collect($response->json('return_options'));

    $yiAirline = $options->firstWhere('airline_code', 'YI');
    $otherAirline = $options->firstWhere('airline_code', '5S');

    expect($yiAirline['pricing_method'])->toBe('roundtrip')
        ->and((float) $yiAirline['pricing']['total'])->toBe(150.0)
        ->and((float) $yiAirline['pricing_total_roundtrip'])->toBe(350.0)
        ->and($otherAirline['pricing_method'])->toBe('oneway')
        ->and((float) $otherAirline['pricing']['total'])->toBe(210.0);
});

test('return options endpoint accepts an overridden return date', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->postJson($baseUrl.route('flights.return-options', [], false), [
        'uuid' => 'rt-123',
        'outbound_provider_id' => $state['providerA']->id,
        'return_date' => '2026-05-12',
        'outbound_flight' => [
            'airline_code' => 'YI',
            'airline_name' => 'Oya',
            'flight_number' => '0500',
            'departure_airport' => 'MJI',
            'arrival_airport' => 'IST',
            'departure_time' => '2026-05-01 10:00:00',
            'segments' => [
                [
                    'flight_number' => '0500',
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
        ->assertJsonPath('return_date', '2026-05-12');
});

test('return options endpoint accepts force refresh flag', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->postJson($baseUrl.route('flights.return-options', [], false), [
        'uuid' => 'rt-123',
        'outbound_provider_id' => $state['providerA']->id,
        'force_refresh' => true,
        'outbound_flight' => [
            'airline_code' => 'YI',
            'airline_name' => 'Oya',
            'flight_number' => '0500',
            'departure_airport' => 'MJI',
            'arrival_airport' => 'IST',
            'departure_time' => '2026-05-01 10:00:00',
            'segments' => [
                [
                    'flight_number' => '0500',
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
        ->assertJsonStructure(['return_options']);
});
