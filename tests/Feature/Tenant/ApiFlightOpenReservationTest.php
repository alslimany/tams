<?php

use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-fltopen-'.Str::random(4),
        'company_name' => 'API Flight Open Reservation',
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
    $state['token'] = $user->createToken('Test Device', ['read'])->plainTextToken;
    $state['providerId'] = $provider->id;

    $providerMock = Mockery::mock();
    $state['providerMock'] = $providerMock;

    Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    Mockery::close();
});

test('api open reservation availability returns provider eligibility', function () {
    global $state;

    $state['providerMock']->shouldReceive('canBookOpenReservation')
        ->once()
        ->andReturn(true);

    $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/open-reservation-availability', [
            'provider_id' => $state['providerId'],
            'flight' => [
                'flight_number' => 'YI500',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'IST',
                'departure_time' => '2026-06-15 10:00',
                'pricing' => [
                    'class_code' => 'Y',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.allowed', true);
});

test('api open reservation availability returns false when provider does not support it', function () {
    global $state;

    $state['providerMock']->shouldReceive('canBookOpenReservation')
        ->once()
        ->andReturn(false);

    $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/open-reservation-availability', [
            'provider_id' => $state['providerId'],
            'flight' => [
                'flight_number' => 'YI500',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'IST',
                'departure_time' => '2026-06-15 10:00',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.allowed', false);
});

test('api open reservation availability returns 404 for unknown provider', function () {
    global $state;

    $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/open-reservation-availability', [
            'provider_id' => 999999,
            'flight' => [
                'flight_number' => 'YI500',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'IST',
                'departure_time' => '2026-06-15 10:00',
            ],
        ])
        ->assertNotFound();
});
