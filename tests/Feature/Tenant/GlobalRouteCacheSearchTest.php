<?php

use App\Models\FlightScheduleCache;
use App\Models\RouteAvailabilityCache;
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
        'id' => 'global-cache-'.Str::random(4),
        'company_name' => 'Global Cache Tenant',
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

    $state['providerYI'] = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'YI1',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    $state['providerUZ'] = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'UZ',
        'airline_name' => 'Buraq',
        'account_name' => 'UZ1',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    Cache::put('flight_search_global-cache', [
        'origin' => 'MJI',
        'destination' => 'BEN',
        'date' => '2026-07-01',
        'adults' => 1,
        'children' => 0,
        'infants' => 0,
        'is_return' => false,
    ], now()->addMinutes(30));
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

test('results page filters out provider marked unavailable for route', function () {
    global $state;

    RouteAvailabilityCache::query()->create([
        'airline_code' => 'YI',
        'origin' => 'MJI',
        'destination' => 'BEN',
        'has_flights' => false,
        'consecutive_empty' => 3,
        'last_checked_at' => now(),
    ]);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->get($baseUrl.route('flights.results', ['uuid' => 'global-cache'], false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Bookings/SearchResults')
            ->has('providers', 1)
            ->where('providers.0.id', $state['providerUZ']->id)
            ->where("providerSources.{$state['providerUZ']->id}.provider_selector", 'own:'.$state['providerUZ']->id)
            ->where("providerSources.{$state['providerUZ']->id}.source_type", 'own')
            ->where("providerSources.{$state['providerUZ']->id}.source_provider_id", $state['providerUZ']->id)
        );
});

test('fetch flights learns route availability and stores day pricing cache', function () {
    global $state;

    $this->actingAs($state['user']);

    $providerMock = \Mockery::mock(AirlineProviderInterface::class);
    $providerMock->shouldReceive('searchAvailability')
        ->once()
        ->andReturn([
            [
                'flight_number' => '0500',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'BEN',
                'departure_time' => '2026-07-01 10:00:00',
                'arrival_time' => '2026-07-01 11:00:00',
                'segments' => [[
                    'flight_number' => '0500',
                    'class' => 'Y',
                    'departure_airport' => 'MJI',
                    'arrival_airport' => 'BEN',
                    'departure_time' => '2026-07-01 10:00:00',
                ]],
                'pricing' => ['total' => 215, 'currency' => 'LYD'],
            ],
        ]);

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->once()
        ->andReturn($providerMock);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->postJson($baseUrl.route('flights.fetch-flights', [], false), [
        'uuid' => 'global-cache',
        'provider_id' => $state['providerYI']->id,
    ])->assertOk();

    expect(RouteAvailabilityCache::query()
        ->where('airline_code', 'YI')
        ->where('origin', 'MJI')
        ->where('destination', 'BEN')
        ->value('has_flights'))
        ->toBeTrue();

    $scheduleEntry = FlightScheduleCache::query()
        ->where('airline_code', 'YI')
        ->where('origin', 'MJI')
        ->where('destination', 'BEN')
        ->whereDate('flight_date', '2026-07-01')
        ->first();

    expect($scheduleEntry)->not->toBeNull()
        ->and((float) $scheduleEntry->lowest_price)->toBe(215.0)
        ->and($scheduleEntry->currency)->toBe('LYD');
});
