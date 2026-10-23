<?php

use App\DTOs\Airline\RoundTripPriceResult;
use App\Exceptions\NoFareAvailableException;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Airline\AirlineProviderInterface;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function (): void {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-rtguard-'.Str::random(4),
        'company_name' => 'API Round Trip Pricing Guard',
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
});

afterEach(function (): void {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

/**
 * @return array<string, mixed>
 */
function guardFlight(
    string $id,
    string $airlineCode,
    string $flightNumber,
    string $origin,
    string $destination,
    string $departureTime,
    float $total,
): array {
    return [
        'id' => $id,
        'airline_code' => $airlineCode,
        'airline_name' => $airlineCode,
        'flight_number' => $flightNumber,
        'departure_airport' => $origin,
        'arrival_airport' => $destination,
        'departure_time' => $departureTime,
        'arrival_time' => $departureTime,
        'available_seats' => 9,
        'segments' => [[
            'flight_number' => $flightNumber,
            'class' => 'Y',
            'departure_airport' => $origin,
            'arrival_airport' => $destination,
            'departure_time' => $departureTime,
            'arrival_time' => $departureTime,
        ]],
        'pricing' => ['total' => $total, 'currency' => 'LYD', 'class_code' => 'Y'],
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $outbound
 * @param  array<int, array<string, mixed>>  $return
 */
function guardFakeProvider(array $outbound, array $return, ?RoundTripPriceResult $quote = null, bool $quoteThrows = false): AirlineProviderInterface
{
    $provider = \Mockery::mock(AirlineProviderInterface::class);
    $provider->shouldReceive('searchAvailability')->andReturn($outbound);
    $provider->shouldReceive('searchReturnLeg')->andReturn($return);

    $expectation = $provider->shouldReceive('priceRoundTrip');

    if ($quoteThrows) {
        $expectation->andThrow(new NoFareAvailableException('No round-trip fare.'));
    } else {
        $expectation->andReturn($quote);
    }

    return $provider;
}

function guardSingleProvider(): void
{
    global $state;

    $state['providerB']->delete();
}

function guardRunMatrix(): array
{
    global $state;

    $uuid = test()->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/roundtrip-search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'return_date' => '2026-06-20',
            'adults' => 1,
        ])
        ->assertOk()
        ->json('data.uuid');

    return test()->withToken($state['token'])
        ->getJson($state['apiUrl'].'/flights/roundtrip-results/'.$uuid)
        ->assertOk()
        ->json('data.offers');
}

test('an unpriced return leg drops the pair instead of publishing a free leg', function () {
    global $state;

    guardSingleProvider();

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn(guardFakeProvider(
            [guardFlight('out-1', 'YI', '0500', 'MJI', 'IST', '2026-06-15 10:00:00', 200)],
            [guardFlight('ret-1', 'YI', '0501', 'IST', 'MJI', '2026-06-20 18:00:00', 0)],
            quoteThrows: true,
        ));

    expect(guardRunMatrix())->toBeEmpty();
});

test('published round-trip offers price both itineraries and sum to the package total', function () {
    global $state;

    guardSingleProvider();

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn(guardFakeProvider(
            [guardFlight('out-1', 'YI', '0500', 'MJI', 'IST', '2026-06-15 10:00:00', 200)],
            [guardFlight('ret-1', 'YI', '0501', 'IST', 'MJI', '2026-06-20 18:00:00', 300)],
            new RoundTripPriceResult(returnLegPrice: 300, currency: 'LYD', totalPrice: 500),
        ));

    $offers = guardRunMatrix();

    expect($offers)->toHaveCount(1);

    $offer = $offers[0];

    expect((string) data_get($offer, 'pricing.method'))->toBe('roundtrip')
        ->and((float) data_get($offer, 'pricing.outbound_total'))->toBe(200.0)
        ->and((float) data_get($offer, 'pricing.return_total'))->toBe(300.0)
        ->and((float) data_get($offer, 'pricing.total'))->toBe(500.0)
        ->and((float) data_get($offer, 'itineraries.0.pricing.total'))->toBe(200.0)
        ->and((float) data_get($offer, 'itineraries.1.pricing.total'))->toBe(300.0)
        ->and(abs((float) data_get($offer, 'pricing.total')
            - ((float) data_get($offer, 'pricing.outbound_total') + (float) data_get($offer, 'pricing.return_total')))
        )->toBeLessThan(0.01);
});

test('a return leg priced only by the round-trip quote lands on its itinerary', function () {
    global $state;

    // Availability quotes nothing for the return leg (airline rejects standalone
    // return pricing), exactly like the captured MJI→TUN packages.
    guardSingleProvider();

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn(guardFakeProvider(
            [guardFlight('out-1', 'YI', '0500', 'MJI', 'IST', '2026-06-15 10:00:00', 200)],
            [guardFlight('ret-1', 'YI', '0501', 'IST', 'MJI', '2026-06-20 18:00:00', 0)],
            new RoundTripPriceResult(returnLegPrice: 300, currency: 'LYD', totalPrice: 500),
        ));

    $offers = guardRunMatrix();

    expect($offers)->toHaveCount(1);

    $offer = $offers[0];

    expect((string) data_get($offer, 'pricing.method'))->toBe('roundtrip')
        ->and((float) data_get($offer, 'pricing.outbound_total'))->toBe(200.0)
        ->and((float) data_get($offer, 'pricing.return_total'))->toBe(300.0)
        ->and((float) data_get($offer, 'pricing.total'))->toBe(500.0)
        ->and((float) data_get($offer, 'itineraries.1.pricing.total'))->toBe(300.0)
        ->and((float) data_get($offer, 'itineraries.0.pricing.total'))->toBe(200.0);
});

test('every priced pair publishes as a roundtrip package, mixed provider or not', function () {
    global $state;

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturnUsing(fn (TenantProvider $provider) => guardFakeProvider(
            [guardFlight('out-'.$provider->id, $provider->airline_code, '0500', 'MJI', 'IST', '2026-06-15 10:00:00', 200)],
            [guardFlight('ret-'.$provider->id, $provider->airline_code, '0501', 'IST', 'MJI', '2026-06-20 18:00:00', 300)],
            new RoundTripPriceResult(returnLegPrice: 300, currency: 'LYD', totalPrice: 500),
        ));

    $offers = guardRunMatrix();

    expect($offers)->toHaveCount(4);

    $containsMixedPair = false;

    foreach ($offers as $offer) {
        $containsMixedPair = $containsMixedPair
            || (int) data_get($offer, 'legs.0.provider_id') !== (int) data_get($offer, 'legs.1.provider_id');

        expect((string) data_get($offer, 'pricing.method'))->toBe('roundtrip')
            ->and((float) data_get($offer, 'pricing.outbound_total'))->toBe(200.0)
            ->and((float) data_get($offer, 'pricing.return_total'))->toBe(300.0)
            ->and((float) data_get($offer, 'pricing.total'))->toBe(500.0)
            ->and((float) data_get($offer, 'itineraries.0.pricing.total'))->toBe(200.0)
            ->and((float) data_get($offer, 'itineraries.1.pricing.total'))->toBe(300.0);
    }

    expect($containsMixedPair)->toBeTrue();
});

test('the round-trip quote fills baggage that return availability never had', function () {
    global $state;

    guardSingleProvider();

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn(guardFakeProvider(
            [guardFlight('out-1', 'YI', '0500', 'MJI', 'IST', '2026-06-15 10:00:00', 200)],
            [guardFlight('ret-1', 'YI', '0501', 'IST', 'MJI', '2026-06-20 18:00:00', 300)],
            new RoundTripPriceResult(
                returnLegPrice: 300,
                currency: 'LYD',
                totalPrice: 500,
                segmentBaggage: [
                    1 => ['hold_weight' => '20K', 'hand_weight' => '7K', 'hold_pieces' => '2'],
                    2 => ['hold_weight' => '23K', 'hand_weight' => '10K', 'hold_pieces' => '1'],
                ],
            ),
        ));

    $offers = guardRunMatrix();

    expect($offers)->toHaveCount(1)
        ->and(data_get($offers, '0.itineraries.0.baggage.hold_weight'))->toBe('20K')
        ->and(data_get($offers, '0.itineraries.0.baggage.hold_pieces'))->toBe('2')
        ->and(data_get($offers, '0.itineraries.1.baggage.hold_weight'))->toBe('23K')
        ->and(data_get($offers, '0.itineraries.1.baggage.hand_weight'))->toBe('10K')
        ->and(data_get($offers, '0.itineraries.1.baggage.hold_pieces'))->toBe('1');
});

test('a repriced leg keeps a breakdown that sums to its allocated total', function () {
    global $state;

    guardSingleProvider();

    $outbound = guardFlight('out-1', 'YI', '0500', 'MJI', 'IST', '2026-06-15 10:00:00', 200);
    $outbound['pricing']['breakdown'] = [
        ['label' => 'Adult (x1)', 'qty' => 1, 'fare' => 150.0, 'tax' => 50.0, 'amount' => 200.0],
    ];

    // The quote prices the outbound half at 220 while availability said 200.
    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn(guardFakeProvider(
            [$outbound],
            [guardFlight('ret-1', 'YI', '0501', 'IST', 'MJI', '2026-06-20 18:00:00', 300)],
            new RoundTripPriceResult(returnLegPrice: 300, currency: 'LYD', totalPrice: 520),
        ));

    $offers = guardRunMatrix();

    $line = data_get($offers, '0.itineraries.0.pricing.breakdown.0');

    expect($offers)->toHaveCount(1)
        ->and((float) data_get($offers, '0.itineraries.0.pricing.total'))->toBe(220.0)
        ->and((float) ($line['amount'] ?? 0))->toBe(220.0)
        ->and((float) ($line['fare'] ?? 0) + (float) ($line['tax'] ?? 0))->toBe(220.0);
});

test('a same-token quote reconciles outbound_total so the legs keep summing', function () {
    global $state;

    // The quote prices the outbound half at 220 while availability said 200.
    guardSingleProvider();

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn(guardFakeProvider(
            [guardFlight('out-1', 'YI', '0500', 'MJI', 'IST', '2026-06-15 10:00:00', 200)],
            [guardFlight('ret-1', 'YI', '0501', 'IST', 'MJI', '2026-06-20 18:00:00', 300)],
            new RoundTripPriceResult(returnLegPrice: 300, currency: 'LYD', totalPrice: 520),
        ));

    $offers = guardRunMatrix();

    expect($offers)->toHaveCount(1)
        ->and((float) data_get($offers, '0.pricing.outbound_total'))->toBe(220.0)
        ->and((float) data_get($offers, '0.pricing.return_total'))->toBe(300.0)
        ->and((float) data_get($offers, '0.pricing.total'))->toBe(520.0)
        // Each itinerary shows the half the package allocated, not the raw
        // availability price it was fetched with.
        ->and((float) data_get($offers, '0.itineraries.0.pricing.total'))->toBe(220.0)
        ->and((float) data_get($offers, '0.itineraries.1.pricing.total'))->toBe(300.0);
});

test('the matrix only publishes pairs where every leg is priced', function () {
    global $state;

    $providerA = \Mockery::mock(AirlineProviderInterface::class);
    $providerA->shouldReceive('searchAvailability')->andReturn([
        guardFlight('yi-out', 'YI', '0500', 'MJI', 'IST', '2026-06-15 10:00:00', 200),
    ]);
    $providerA->shouldReceive('searchReturnLeg')->andReturn([
        guardFlight('yi-ret', 'YI', '0501', 'IST', 'MJI', '2026-06-20 18:00:00', 400),
    ]);
    $providerA->shouldReceive('priceRoundTrip')
        ->andThrow(new NoFareAvailableException('No round-trip fare.'));

    // 5S cannot price its own return leg at all.
    $providerB = \Mockery::mock(AirlineProviderInterface::class);
    $providerB->shouldReceive('searchAvailability')->andReturn([
        guardFlight('5s-out', '5S', '0750', 'MJI', 'IST', '2026-06-15 12:00:00', 150),
    ]);
    $providerB->shouldReceive('searchReturnLeg')->andReturn([
        guardFlight('5s-ret', '5S', '0755', 'IST', 'MJI', '2026-06-20 21:00:00', 0),
    ]);
    $providerB->shouldReceive('priceRoundTrip')
        ->andThrow(new NoFareAvailableException('No round-trip fare.'));

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturnUsing(fn (TenantProvider $provider) => $provider->airline_code === 'YI' ? $providerA : $providerB);

    $offers = collect(guardRunMatrix());

    // YIxYI (600) and 5SxYI (550) survive; YIx5S and 5Sx5S carry a free return leg.
    expect($offers)->toHaveCount(2);

    foreach ($offers as $offer) {
        $outboundTotal = (float) data_get($offer, 'pricing.outbound_total');
        $returnTotal = (float) data_get($offer, 'pricing.return_total');
        $total = (float) data_get($offer, 'pricing.total');

        expect($outboundTotal)->toBeGreaterThan(0)
            ->and($returnTotal)->toBeGreaterThan(0)
            ->and(abs($total - ($outboundTotal + $returnTotal)))->toBeLessThan(0.01)
            ->and((float) data_get($offer, 'itineraries.0.pricing.total'))->toBeGreaterThan(0)
            ->and((float) data_get($offer, 'itineraries.1.pricing.total'))->toBeGreaterThan(0);
    }

    expect($offers->contains(fn (array $offer): bool => (string) data_get($offer, 'legs.1.airline_code') === '5S'))->toBeFalse();
});

test('open reservation maps every matrix leg to a real segment on its own provider', function () {
    global $state;

    $seenSegments = [];

    $yiMock = \Mockery::mock();
    $yiMock->shouldReceive('canBookOpenReservation')
        ->once()
        ->andReturnUsing(function (array $segment) use (&$seenSegments): bool {
            $seenSegments['outbound'] = $segment;

            return true;
        });

    $uzMock = \Mockery::mock();
    $uzMock->shouldReceive('canBookOpenReservation')
        ->once()
        ->andReturnUsing(function (array $segment) use (&$seenSegments): bool {
            $seenSegments['return'] = $segment;

            return true;
        });

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturnUsing(fn (TenantProvider $provider) => $provider->airline_code === 'YI' ? $yiMock : $uzMock);

    $response = test()->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/open-reservation-availability', [
            'provider_id' => $state['providerA']->id,
            'flight' => [
                'offer_id' => 'rt_matrix',
                'pricing' => ['total' => 500, 'currency' => 'LYD', 'method' => 'oneway'],
                'legs' => [
                    ['provider_id' => $state['providerA']->id, 'airline_code' => 'YI', 'itinerary_index' => 0],
                    ['provider_id' => $state['providerB']->id, 'airline_code' => '5S', 'itinerary_index' => 1],
                ],
                'itineraries' => [
                    [
                        'flight_number' => '0500',
                        'departure_airport' => 'MJI',
                        'arrival_airport' => 'TUN',
                        'departure_time' => '2026-10-22 10:00:00',
                        'segments' => [[
                            'flight_number' => '0500',
                            'class' => 'Y',
                            'departure_airport' => 'MJI',
                            'arrival_airport' => 'TUN',
                            'departure_time' => '2026-10-22 10:00:00',
                        ]],
                    ],
                    [
                        'flight_number' => '0755',
                        'departure_airport' => 'TUN',
                        'arrival_airport' => 'MJI',
                        'departure_time' => '2026-10-23 18:00:00',
                        'segments' => [[
                            'flight_number' => '0755',
                            'class' => 'Y',
                            'departure_airport' => 'TUN',
                            'arrival_airport' => 'MJI',
                            'departure_time' => '2026-10-23 18:00:00',
                        ]],
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.allowed', true);

    expect($seenSegments)->toHaveKeys(['outbound', 'return'])
        ->and($seenSegments['outbound']['flt_no'])->toBe('0500')
        ->and($seenSegments['outbound']['origin'])->toBe('MJI')
        ->and($seenSegments['outbound']['dest'])->toBe('TUN')
        ->and($seenSegments['return']['flt_no'])->toBe('0755')
        ->and($seenSegments['return']['origin'])->toBe('TUN')
        ->and($seenSegments['return']['dest'])->toBe('MJI');

    $response->assertJsonPath('data.legs.0.itinerary_index', 0)
        ->assertJsonPath('data.legs.0.provider_id', $state['providerA']->id)
        ->assertJsonPath('data.legs.0.allowed', true)
        ->assertJsonPath('data.legs.1.itinerary_index', 1)
        ->assertJsonPath('data.legs.1.provider_id', $state['providerB']->id)
        ->assertJsonPath('data.legs.1.allowed', true);
});

test('open reservation allowed is false when any single matrix leg is blocked', function () {
    global $state;

    $yiMock = \Mockery::mock();
    $yiMock->shouldReceive('canBookOpenReservation')->once()->andReturn(true);

    $uzMock = \Mockery::mock();
    $uzMock->shouldReceive('canBookOpenReservation')->once()->andReturn(false);

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturnUsing(fn (TenantProvider $provider) => $provider->airline_code === 'YI' ? $yiMock : $uzMock);

    test()->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/open-reservation-availability', [
            'provider_id' => $state['providerA']->id,
            'flight' => [
                'legs' => [
                    ['provider_id' => $state['providerA']->id, 'itinerary_index' => 0],
                    ['provider_id' => $state['providerB']->id, 'itinerary_index' => 1],
                ],
                'itineraries' => [
                    ['segments' => [['flight_number' => '0500', 'departure_airport' => 'MJI', 'arrival_airport' => 'TUN', 'departure_time' => '2026-10-22 10:00:00']]],
                    ['segments' => [['flight_number' => '0755', 'departure_airport' => 'TUN', 'arrival_airport' => 'MJI', 'departure_time' => '2026-10-23 18:00:00']]],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.allowed', false)
        ->assertJsonPath('data.legs.0.allowed', true)
        ->assertJsonPath('data.legs.1.allowed', false);
});

test('polling endpoints survive above the general api budget while normal endpoints still throttle', function () {
    global $state;

    // 61+ polls must not trip the shared 60/min budget any more.
    for ($i = 0; $i < 65; $i++) {
        test()->withToken($state['token'])
            ->getJson($state['apiUrl'].'/flights/roundtrip-results/'.Str::uuid())
            ->assertStatus(410);
    }

    // A normal read endpoint must still hit 429 once the 60/min budget is spent.
    $throttled = false;

    for ($i = 0; $i < 65; $i++) {
        $status = test()->withToken($state['token'])
            ->getJson($state['apiUrl'].'/airlines')
            ->status();

        if ($status === 429) {
            $throttled = true;

            break;
        }
    }

    expect($throttled)->toBeTrue();
});

test('booking a same-token round trip sends both legs to the provider', function () {
    global $state;

    tenancy()->initialize($state['tenant']);
    $state['providerA']->getOrCreateCurrencyWallet('LYD')->depositFloat(2000, ['type' => 'seed_provider_balance']);
    $state['providerB']->getOrCreateCurrencyWallet('LYD')->depositFloat(2000, ['type' => 'seed_provider_balance']);

    $outbound = [guardFlight('out-1', 'YI', '0500', 'MJI', 'IST', '2026-06-15 10:00:00', 200)];
    $return = [guardFlight('ret-1', 'YI', '0501', 'IST', 'MJI', '2026-06-20 18:00:00', 150)];

    $bookedItinerary = null;

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('searchAvailability')->andReturn($outbound);
    $providerMock->shouldReceive('searchReturnLeg')->andReturn($return);
    $providerMock->shouldReceive('priceRoundTrip')->andReturn(new RoundTripPriceResult(150, 'LYD', 350));
    $providerMock->shouldReceive('getPricing')->andReturn('<FareQuote />');
    $providerMock->shouldReceive('createBooking')
        ->once()
        ->andReturnUsing(function (array $payload) use (&$bookedItinerary): string {
            $bookedItinerary = $payload['itinerary'];

            return '<PNR RLOC="RT123"></PNR>';
        });

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $uuid = test()->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/roundtrip-search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'return_date' => '2026-06-20',
            'adults' => 1,
        ])
        ->json('data.uuid');

    // Pick a same-token (YIxYI) offer so both legs share one provider.
    $offer = collect(test()->withToken($state['token'])
        ->getJson($state['apiUrl'].'/flights/roundtrip-results/'.$uuid)
        ->json('data.offers'))
        ->first(fn (array $o): bool => data_get($o, 'legs.0.provider_id') === data_get($o, 'legs.1.provider_id'));

    expect($offer)->not->toBeNull();

    test()->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/select', ['uuid' => $uuid, 'offer' => $offer])
        ->assertOk();

    test()->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/book', [
            'uuid' => $uuid,
            'passengers' => [[
                'type' => 'adult', 'first_name' => 'John', 'last_name' => 'Doe',
                'dob' => '1990-01-01', 'gender' => 'M',
                'passport_number' => 'A1234567', 'passport_expiry' => '2030-01-01',
                'passport_issue_country' => 'LBY', 'nationality' => 'LBY',
            ]],
            'customer' => ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com', 'phone' => '0911234567'],
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.items.0.product_subtype', 'roundtrip')
        ->assertJsonPath('data.items.0.provider_reference', 'RT123');

    expect($bookedItinerary)->toBeArray()
        ->and($bookedItinerary)->toHaveCount(2)
        ->and($bookedItinerary[0]['flt_no'])->toBe(data_get($offer, 'itineraries.0.flight_number'))
        ->and($bookedItinerary[1]['flt_no'])->toBe(data_get($offer, 'itineraries.1.flight_number'));
});

test('booking a mixed-provider round trip is rejected with 422', function () {
    global $state;

    $yiMock = \Mockery::mock();
    $yiMock->shouldReceive('searchAvailability')->andReturn([guardFlight('out-1', 'YI', '0500', 'MJI', 'IST', '2026-06-15 10:00:00', 200)]);
    $yiMock->shouldReceive('searchReturnLeg')->andReturn([guardFlight('ret-1', 'YI', '0501', 'IST', 'MJI', '2026-06-20 18:00:00', 150)]);
    $yiMock->shouldReceive('priceRoundTrip')->andReturn(new RoundTripPriceResult(150, 'LYD', 350));

    $uzMock = \Mockery::mock();
    $uzMock->shouldReceive('searchAvailability')->andReturn([guardFlight('out-2', '5S', '0750', 'MJI', 'IST', '2026-06-15 12:00:00', 180)]);
    $uzMock->shouldReceive('searchReturnLeg')->andReturn([guardFlight('ret-2', '5S', '0755', 'IST', 'MJI', '2026-06-20 21:00:00', 210)]);

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturnUsing(fn (TenantProvider $provider) => $provider->airline_code === 'YI' ? $yiMock : $uzMock);

    $uuid = test()->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/roundtrip-search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'return_date' => '2026-06-20',
            'adults' => 1,
        ])
        ->json('data.uuid');

    // A mixed package (YI outbound + 5S return) must never be ticketed on one token.
    $offer = collect(test()->withToken($state['token'])
        ->getJson($state['apiUrl'].'/flights/roundtrip-results/'.$uuid)
        ->json('data.offers'))
        ->first(fn (array $o): bool => data_get($o, 'legs.0.provider_id') !== data_get($o, 'legs.1.provider_id'));

    expect($offer)->not->toBeNull();

    test()->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/select', ['uuid' => $uuid, 'offer' => $offer])
        ->assertOk();

    test()->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/book', [
            'uuid' => $uuid,
            'passengers' => [['type' => 'adult', 'first_name' => 'John', 'last_name' => 'Doe']],
            'customer' => ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This round trip mixes two airline tokens and cannot be issued as one booking. Select a round trip on a single airline.');
});
