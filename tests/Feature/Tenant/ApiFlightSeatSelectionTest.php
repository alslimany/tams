<?php

use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-fltseat-'.Str::random(4),
        'company_name' => 'API Flight Seat Agency',
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

    TenantProvider::query()->create([
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
    $state['providerId'] = TenantProvider::query()->value('id');
    $state['uuid'] = (string) Str::uuid();

    Cache::put("flight_search_{$state['uuid']}", [
        'origin' => 'MJI',
        'destination' => 'IST',
        'date' => '2026-06-15',
        'adults' => 1,
        'selected_offer' => [
            'provider_id' => $state['providerId'],
            'flight' => [
                'flight_number' => 'YI500',
                'class' => 'Y',
                'departure_airport' => 'MJI',
                'arrival_airport' => 'IST',
                'departure_time' => '2026-06-15 10:00',
                'arrival_time' => '2026-06-15 14:00',
                'segments' => [[
                    'flight_number' => '500',
                    'class' => 'Y',
                    'departure_airport' => 'MJI',
                    'arrival_airport' => 'IST',
                    'departure_time' => '2026-06-15 10:00',
                    'arrival_time' => '2026-06-15 14:00',
                ]],
                'pricing' => [
                    'total' => 450,
                    'currency' => 'LYD',
                ],
            ],
            'reservation_type' => 'NN',
        ],
    ], now()->addMinutes(30));
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

function mockProviderForSeatApi(): object
{
    return new class
    {
        public function getSeatMap(string $fltNo, string $date): array
        {
            return [
                'grid' => ['max_row' => 30, 'max_col' => 6],
                'cabins' => [['class' => 'Y', 'seats' => 150]],
                'seats' => [
                    [
                        'row' => 12,
                        'col' => 1,
                        'code' => '12A',
                        'price' => 25.0,
                        'is_occupied' => false,
                        'is_aisle' => false,
                    ],
                ],
            ];
        }

        public function getPricing(array $itinerary, array $passengers): array
        {
            return ['success' => true];
        }

        public function getAncillaryCatalog(array $flight = [], array $searchParams = []): array
        {
            return [];
        }

        public function createBooking(array $params): array
        {
            return ['rloc' => 'ABC123'];
        }
    };
}

test('seatmap endpoint returns provider seat map', function () {
    global $state;

    Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn(mockProviderForSeatApi());

    $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/seatmap', [
            'provider_id' => $state['providerId'],
            'flight_number' => '500',
            'date' => '2026-06-15',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.seats.0.code', '12A')
        ->assertJsonPath('data.seats.0.price', 25);
});

test('price endpoint includes selected seat fees in grand total', function () {
    global $state;

    Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn(mockProviderForSeatApi());

    $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/price', [
            'uuid' => $state['uuid'],
            'passengers' => [
                ['type' => 'adult', 'first_name' => 'John', 'last_name' => 'Doe'],
            ],
            'extras' => [
                'seats' => [
                    0 => [1 => '12A'],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.base_fare', 450)
        ->assertJsonPath('data.seats_total', 25)
        ->assertJsonPath('data.grand_total', 475)
        ->assertJsonPath('data.provider_pricing_verified', true);
});

test('book endpoint accepts seat selections in extras', function () {
    global $state;

    $providerMock = Mockery::mock(mockProviderForSeatApi());
    $providerMock->shouldReceive('getPricing')->andReturn(['success' => true]);
    $providerMock->shouldReceive('createBooking')
        ->once()
        ->with(Mockery::on(function (array $payload): bool {
            return ($payload['extras']['seats'][0][1] ?? null) === '12A';
        }))
        ->andReturn(['rloc' => 'ABC123']);

    Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/book', [
            'uuid' => $state['uuid'],
            'passengers' => [
                ['type' => 'adult', 'first_name' => 'John', 'last_name' => 'Doe'],
            ],
            'customer' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
            ],
            'extras' => [
                'seats' => [
                    0 => [1 => '12A'],
                ],
            ],
            'ticketing_mode' => 'draft',
        ])
        ->assertCreated()
        ->assertJsonPath('success', true);
});

test('price without selected flight returns 410', function () {
    global $state;

    $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/price', [
            'uuid' => (string) Str::uuid(),
            'passengers' => [
                ['type' => 'adult'],
            ],
        ])
        ->assertStatus(410);
});
