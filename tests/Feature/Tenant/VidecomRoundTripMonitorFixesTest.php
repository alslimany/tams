<?php

use App\DTOs\Airline\RoundTripPriceRequest;
use App\DTOs\Airline\RoundTripPriceResult;
use App\Exceptions\NoFareAvailableException;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Airline\AirlineProviderInterface;
use Illuminate\Support\Str;

/**
 * Regressions from the read-only Videcom monitor run
 * storage/app/videcom-monitor/20261023-170420.md (MJI-TUN, UZ and BM).
 */

/** @var array<string, mixed> $vrmState */
$vrmState = [];

beforeEach(function (): void {
    global $vrmState;

    $tenant = Tenant::create([
        'id' => 'api-vrm-'.Str::random(4),
        'company_name' => 'Videcom Monitor Fixes',
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

    $vrmState['provider'] = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'UZ',
        'airline_name' => 'Buraq',
        'account_name' => 'Default Account',
        'credentials' => ['base_url' => 'https://example.com', 'currency' => 'LYD'],
        'is_active' => true,
        'commission_own' => 5,
    ]);

    $vrmState['tenant'] = $tenant;
    $vrmState['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
    $vrmState['token'] = $user->createToken('Test Device', ['read', 'write'])->plainTextToken;
});

afterEach(function (): void {
    global $vrmState;

    tenancy()->end();
    $vrmState['tenant']->delete();
    \Mockery::close();
});

/**
 * @return array<string, mixed>
 */
function vrmFlight(string $flightNumber, string $class, string $origin, string $destination, string $departureTime, float $total): array
{
    return [
        'id' => "{$flightNumber}-{$class}",
        'airline_code' => 'UZ',
        'airline_name' => 'UZ',
        'flight_number' => $flightNumber,
        'departure_airport' => $origin,
        'arrival_airport' => $destination,
        'departure_time' => $departureTime,
        'arrival_time' => $departureTime,
        'available_seats' => 9,
        'segments' => [[
            'flight_number' => $flightNumber,
            'class' => $class,
            'departure_airport' => $origin,
            'arrival_airport' => $destination,
            'departure_time' => $departureTime,
            'arrival_time' => $departureTime,
        ]],
        'pricing' => ['total' => $total, 'currency' => 'LYD', 'class_code' => $class],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function vrmRunMatrix(): array
{
    global $vrmState;

    $uuid = test()->withToken($vrmState['token'])
        ->postJson($vrmState['apiUrl'].'/flights/roundtrip-search', [
            'origin' => 'MJI',
            'destination' => 'TUN',
            'date' => '2026-10-26',
            'return_date' => '2026-10-29',
            'adults' => 1,
        ])
        ->assertOk()
        ->json('data.uuid');

    return test()->withToken($vrmState['token'])
        ->getJson($vrmState['apiUrl'].'/flights/roundtrip-results/'.$uuid)
        ->assertOk()
        ->json('data.offers');
}

/**
 * UZ MJI-TUN: 0213 H out at 740. Return Y is sold out (Videcom refuses the
 * round-trip quote), return H is 740 one-way but 545 inside a round trip.
 */
function vrmUzProvider(): AirlineProviderInterface
{
    $provider = \Mockery::mock(AirlineProviderInterface::class);
    $provider->shouldReceive('searchAvailability')->andReturn([
        vrmFlight('0213', 'H', 'MJI', 'TUN', '2026-10-26 08:00:00', 740),
    ]);
    $provider->shouldReceive('searchReturnLeg')->andReturn([
        vrmFlight('0214', 'Y', 'TUN', 'MJI', '2026-10-29 10:00:00', 530),
        vrmFlight('0218', 'Y', 'TUN', 'MJI', '2026-10-29 18:00:00', 530),
        vrmFlight('0220', 'Y', 'TUN', 'MJI', '2026-10-29 20:00:00', 530),
        vrmFlight('0214', 'H', 'TUN', 'MJI', '2026-10-29 10:00:00', 740),
    ]);
    $provider->shouldReceive('priceRoundTrip')->andReturnUsing(function (RoundTripPriceRequest $request): RoundTripPriceResult {
        if (($request->returnSegment['class'] ?? '') === 'Y') {
            throw new NoFareAvailableException('Provider returned no round-trip fare quote: ERROR: NO AV, Y CLASS SOLD OUT ON THIS SERVICE');
        }

        return new RoundTripPriceResult(returnLegPrice: 545, currency: 'LYD', totalPrice: 1285);
    });

    return $provider;
}

test('#4 a same-token pair is priced with the Videcom round-trip quote even when cheaper pairs are refused', function () {
    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn(vrmUzProvider());

    $offer = collect(vrmRunMatrix())->first(
        fn (array $o): bool => data_get($o, 'itineraries.1.flight_number') === '0214'
            && data_get($o, 'itineraries.1.segments.0.class') === 'H'
    );

    expect($offer)->not->toBeNull()
        ->and((float) data_get($offer, 'pricing.total'))->toBe(1285.0)
        ->and((float) data_get($offer, 'pricing.outbound_total'))->toBe(740.0)
        ->and((float) data_get($offer, 'pricing.return_total'))->toBe(545.0);
});

test('#3 a same-token round trip Videcom refuses is never sold at the one-way sum', function () {
    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn(vrmUzProvider());

    $offers = collect(vrmRunMatrix());

    // The three Y returns were refused ("Y CLASS SOLD OUT"); only H/H is sellable.
    expect($offers)->toHaveCount(1)
        ->and($offers->contains(fn (array $o): bool => data_get($o, 'itineraries.1.segments.0.class') === 'Y'))->toBeFalse()
        ->and((float) data_get($offers->first(), 'pricing.total'))->toBe(1285.0);
});

test('combined round-trip pairs carry the real seat count of a 1-seat BM 0400 R outbound', function () {
    TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'BM',
        'airline_name' => 'Medsky',
        'account_name' => 'Default Account',
        'credentials' => ['base_url' => 'https://example.com', 'currency' => 'LYD'],
        'is_active' => true,
        'commission_own' => 5,
    ]);

    $bmOut = vrmFlight('0400', 'R', 'MJI', 'TUN', '2026-10-26 08:40:00', 490);
    $bmOut['airline_code'] = 'BM';
    $bmOut['available_seats'] = 1;

    $bm = \Mockery::mock(AirlineProviderInterface::class);
    $bm->shouldReceive('searchAvailability')->andReturn([$bmOut]);
    $bm->shouldReceive('searchReturnLeg')->andReturn([]);
    $bm->shouldReceive('priceRoundTrip')->andThrow(new NoFareAvailableException('no return'));

    $uz = vrmUzProvider();

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturnUsing(fn (TenantProvider $provider) => $provider->airline_code === 'BM' ? $bm : $uz);

    $combined = collect(vrmRunMatrix())->filter(
        fn (array $o): bool => data_get($o, 'legs.0.airline_code') === 'BM' && data_get($o, 'legs.1.airline_code') === 'UZ'
    );

    expect($combined)->not->toBeEmpty();

    foreach ($combined as $offer) {
        expect((int) data_get($offer, 'itineraries.0.available_seats'))->toBe(1)
            ->and(data_get($offer, 'itineraries.0.flight_number'))->toBe('0400')
            ->and((int) data_get($offer, 'itineraries.1.available_seats'))->toBe(9);
    }
});
