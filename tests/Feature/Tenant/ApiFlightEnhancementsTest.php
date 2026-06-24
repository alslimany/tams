<?php

use App\DTOs\Airline\FlightOption;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-fltenh-'.Str::random(4),
        'company_name' => 'API Flight Enhancements',
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

    $provider = TenantProvider::query()->create([
        'name' => 'Videcom Airways',
        'airline_code' => 'YI',
        'airline_name' => 'Videcom Airways',
        'provider_type' => 'videcom',
        'credentials' => ['base_url' => 'https://example.com', 'api_key' => 'test-key'],
        'is_active' => true,
        'commission_own' => 5,
        'currency' => 'LYD',
    ]);

    $state['tenant'] = $tenant;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
    $state['token'] = $user->createToken('Test Device', ['read', 'write', 'issue'])->plainTextToken;
    $state['providerId'] = $provider->id;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

function mockFlightOffersForCabinFilter(): void
{
    $economy = new FlightOption(
        id: 'yi-eco',
        airline_code: 'YI',
        airline_name: 'Oya',
        flight_number: '500',
        departure_airport: 'MJI',
        arrival_airport: 'IST',
        departure_time: '2026-06-15 10:00',
        arrival_time: '2026-06-15 14:00',
        segments: [['class' => 'Y', 'cabin_type' => 'Y']],
        pricing: ['currency' => 'LYD', 'total' => 400, 'cabin_type' => 'Y', 'hold_weight' => '20K', 'hand_weight' => '7K', 'hold_pieces' => '1', 'fare_id' => '384'],
        available_seats: 5,
    );

    $business = new FlightOption(
        id: 'yi-biz',
        airline_code: 'YI',
        airline_name: 'Oya Business',
        flight_number: '500',
        departure_airport: 'MJI',
        arrival_airport: 'IST',
        departure_time: '2026-06-15 10:00',
        arrival_time: '2026-06-15 14:00',
        segments: [['class' => 'C', 'cabin_type' => 'C']],
        pricing: ['currency' => 'LYD', 'total' => 900, 'cabin_type' => 'C'],
        available_seats: 2,
    );

    $providerMock = Mockery::mock();
    $providerMock->shouldReceive('searchAvailability')->andReturn([$economy, $business]);

    Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);
}

test('flight results include baggage and airline logo url', function () {
    global $state;

    mockFlightOffersForCabinFilter();

    $search = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'is_return' => false,
            'cabin_class' => 'all',
        ]);

    $uuid = $search->json('data.uuid');

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/flights/results/'.$uuid.'?provider_id='.$state['providerId']);

    $response->assertOk()
        ->assertJsonPath('data.offers.0.baggage.hold_weight', '20K')
        ->assertJsonPath('data.offers.0.baggage.hand_weight', '7K')
        ->assertJsonPath('data.offers.0.fare_id', '384')
        ->assertJsonStructure(['data' => ['offers' => [['airline_logo_url']]]]);
});

test('flight results filter by cabin class Y', function () {
    global $state;

    mockFlightOffersForCabinFilter();

    $search = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'adults' => 1,
            'cabin_class' => 'Y',
        ]);

    $uuid = $search->json('data.uuid');

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/flights/results/'.$uuid.'?provider_id='.$state['providerId']);

    $response->assertOk();
    expect($response->json('data.offers'))->toHaveCount(1)
        ->and($response->json('data.offers.0.pricing.cabin_type'))->toBe('Y');
});

test('fare rules endpoint returns rules text', function () {
    global $state;

    $mock = Mockery::mock('alias:App\Services\Airline\ProviderFactory');
    $mock->shouldReceive('make')->andReturn(new class
    {
        public function getFareRules(string $fareId): string
        {
            return "RULE 1: Non refundable for fare {$fareId}";
        }
    });

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/fare-rules', [
            'provider_id' => $state['providerId'],
            'fare_id' => '384',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.fare_id', '384')
        ->assertJsonPath('data.rules', 'RULE 1: Non refundable for fare 384');
});

test('airlines index includes logo url', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/airlines');

    $response->assertOk()
        ->assertJsonPath('data.0.airline_code', 'YI')
        ->assertJsonStructure(['data' => [['airline_code', 'airline_name', 'logo_url']]]);
});
