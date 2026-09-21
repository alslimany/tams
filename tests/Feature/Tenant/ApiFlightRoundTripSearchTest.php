<?php

use App\DTOs\Airline\RoundTripPriceResult;
use App\Jobs\ProcessRoundTripSearchChunkJob;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Airline\AirlineProviderInterface;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function (): void {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-rt-'.Str::random(4),
        'company_name' => 'API Round Trip Agency',
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

    $state['providerA'] = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'Default Account',
        'credentials' => ['base_url' => 'https://example.com', 'currency' => 'LYD'],
        'is_active' => true,
        'commission_own' => 5,
    ]);

    $state['providerB'] = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => '5S',
        'airline_name' => 'Global Air',
        'account_name' => 'Default Account',
        'credentials' => ['base_url' => 'https://example.com', 'currency' => 'LYD'],
        'is_active' => true,
        'commission_own' => 5,
    ]);

    $state['tenant'] = $tenant;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
    $state['token'] = $user->createToken('Test Device', ['read', 'write'])->plainTextToken;
    $state['user'] = $user;
});

afterEach(function (): void {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

function fakeRoundTripProviders(): void
{
    global $state;

    $outboundYi = [
        [
            'id' => 'yi-out-1',
            'airline_code' => 'YI',
            'airline_name' => 'Oya',
            'flight_number' => '0500',
            'departure_airport' => 'MJI',
            'arrival_airport' => 'IST',
            'departure_time' => '2026-06-15 10:00:00',
            'arrival_time' => '2026-06-15 14:00:00',
            'available_seats' => 9,
            'segments' => [[
                'flight_number' => '0500',
                'class' => 'Y',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'IST',
                'departure_time' => '2026-06-15 10:00:00',
                'arrival_time' => '2026-06-15 14:00:00',
            ]],
            'pricing' => ['total' => 200, 'currency' => 'LYD', 'class_code' => 'Y'],
        ],
    ];

    $returnYi = [
        [
            'id' => 'yi-ret-1',
            'airline_code' => 'YI',
            'airline_name' => 'Oya',
            'flight_number' => '0501',
            'departure_airport' => 'IST',
            'arrival_airport' => 'MJI',
            'departure_time' => '2026-06-20 18:00:00',
            'arrival_time' => '2026-06-20 22:00:00',
            'available_seats' => 9,
            'segments' => [[
                'flight_number' => '0501',
                'class' => 'Y',
                'departure_airport' => 'IST',
                'arrival_airport' => 'MJI',
                'departure_time' => '2026-06-20 18:00:00',
                'arrival_time' => '2026-06-20 22:00:00',
            ]],
            'pricing' => ['total' => 999, 'currency' => 'LYD', 'class_code' => 'Y'],
        ],
    ];

    $outbound5s = [
        [
            'id' => '5s-out-1',
            'airline_code' => '5S',
            'airline_name' => 'Global Air',
            'flight_number' => '0750',
            'departure_airport' => 'MJI',
            'arrival_airport' => 'IST',
            'departure_time' => '2026-06-15 12:00:00',
            'arrival_time' => '2026-06-15 16:00:00',
            'available_seats' => 5,
            'segments' => [[
                'flight_number' => '0750',
                'class' => 'Y',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'IST',
                'departure_time' => '2026-06-15 12:00:00',
                'arrival_time' => '2026-06-15 16:00:00',
            ]],
            'pricing' => ['total' => 180, 'currency' => 'LYD', 'class_code' => 'Y'],
        ],
    ];

    $return5s = [
        [
            'id' => '5s-ret-1',
            'airline_code' => '5S',
            'airline_name' => 'Global Air',
            'flight_number' => '0755',
            'departure_airport' => 'IST',
            'arrival_airport' => 'MJI',
            'departure_time' => '2026-06-20 21:00:00',
            'arrival_time' => '2026-06-20 23:00:00',
            'available_seats' => 5,
            'segments' => [[
                'flight_number' => '0755',
                'class' => 'Y',
                'departure_airport' => 'IST',
                'arrival_airport' => 'MJI',
                'departure_time' => '2026-06-20 21:00:00',
                'arrival_time' => '2026-06-20 23:00:00',
            ]],
            'pricing' => ['total' => 210, 'currency' => 'LYD', 'class_code' => 'Y'],
        ],
    ];

    $providerYI = \Mockery::mock(AirlineProviderInterface::class);
    $providerYI->shouldReceive('searchAvailability')->andReturn($outboundYi);
    $providerYI->shouldReceive('searchReturnLeg')->andReturn($returnYi);
    $providerYI->shouldReceive('priceRoundTrip')->andReturn(new RoundTripPriceResult(150, 'LYD', 350));

    $provider5S = \Mockery::mock(AirlineProviderInterface::class);
    $provider5S->shouldReceive('searchAvailability')->andReturn($outbound5s);
    $provider5S->shouldReceive('searchReturnLeg')->andReturn($return5s);

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturnUsing(function ($provider) use ($providerYI, $provider5S) {
            return $provider->airline_code === 'YI' ? $providerYI : $provider5S;
        });
}

test('roundtrip search starts async session and dispatches one chunk job per outbound provider', function () {
    global $state;

    Queue::fake();

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/roundtrip-search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'return_date' => '2026-06-20',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonStructure(['data' => ['uuid', 'providers', 'status', 'search_params']]);

    Queue::assertPushed(ProcessRoundTripSearchChunkJob::class, 2);
});

test('roundtrip search validates return date', function () {
    global $state;

    $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/roundtrip-search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'adults' => 1,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['return_date']);
});

test('roundtrip results return 410 for unknown uuid', function () {
    global $state;

    $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/flights/roundtrip-results/missing-uuid')
        ->assertStatus(410);
});

test('roundtrip matrix prices same token as roundtrip and mixed tokens as oneway', function () {
    global $state;

    fakeRoundTripProviders();

    $start = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/roundtrip-search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'return_date' => '2026-06-20',
            'adults' => 1,
        ])
        ->assertOk();

    $uuid = $start->json('data.uuid');

    $results = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/flights/roundtrip-results/'.$uuid)
        ->assertOk()
        ->assertJsonPath('data.status', 'complete');

    $offers = collect($results->json('data.offers'));

    expect($offers->count())->toBe(4);

    $sameYi = $offers->first(function (array $offer): bool {
        return data_get($offer, 'legs.0.airline_code') === 'YI'
            && data_get($offer, 'legs.1.airline_code') === 'YI';
    });

    $mixed = $offers->first(function (array $offer): bool {
        return data_get($offer, 'legs.0.airline_code') === 'YI'
            && data_get($offer, 'legs.1.airline_code') === '5S';
    });

    expect($sameYi)->not->toBeNull()
        ->and(data_get($sameYi, 'pricing.method'))->toBe('roundtrip')
        ->and((float) data_get($sameYi, 'pricing.total'))->toBe(350.0)
        ->and($mixed)->not->toBeNull()
        ->and(data_get($mixed, 'pricing.method'))->toBe('oneway')
        ->and((float) data_get($mixed, 'pricing.total'))->toBe(410.0)
        ->and(data_get($sameYi, 'itineraries.0.departure_time') <= data_get($sameYi, 'itineraries.1.departure_time'))->toBeTrue();
});

test('cheap_only returns a single cheapest offer', function () {
    global $state;

    fakeRoundTripProviders();

    $uuid = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/roundtrip-search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'return_date' => '2026-06-20',
            'adults' => 1,
            'cheap_only' => true,
        ])
        ->json('data.uuid');

    $offers = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/flights/roundtrip-results/'.$uuid)
        ->assertOk()
        ->json('data.offers');

    expect($offers)->toHaveCount(1)
        ->and((float) data_get($offers, '0.pricing.total'))->toBe(350.0);
});

test('select accepts roundtrip matrix offer payload', function () {
    global $state;

    fakeRoundTripProviders();

    $uuid = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/roundtrip-search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'return_date' => '2026-06-20',
            'adults' => 1,
        ])
        ->json('data.uuid');

    $offer = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/flights/roundtrip-results/'.$uuid)
        ->json('data.offers.0');

    $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/select', [
            'uuid' => $uuid,
            'offer' => $offer,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_round_trip', true)
        ->assertJsonPath('data.flight.round_trip.outbound_flight.flight_number', data_get($offer, 'itineraries.0.flight_number'))
        ->assertJsonPath('data.outbound_provider_id', data_get($offer, 'legs.0.provider_id'))
        ->assertJsonPath('data.return_provider_id', data_get($offer, 'legs.1.provider_id'));
});
