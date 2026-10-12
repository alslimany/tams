<?php

use App\Exceptions\NoFareAvailableException;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Airline\AirlineProviderInterface;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'rt-nofare-'.Str::random(4),
        'company_name' => 'No Fare Agency',
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
    $providerYI->shouldReceive('searchReturnLeg')->once()->andReturn([
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
    $providerYI->shouldReceive('priceRoundTrip')->once()->andThrow(
        new NoFareAvailableException('Provider returned no round-trip fare quote: <PNR/>'),
    );

    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerYI);

    $state['reported'] = new ArrayObject;

    $original = $this->app->make(ExceptionHandler::class);

    $this->app->instance(ExceptionHandler::class, new class($original, $state['reported']) implements ExceptionHandler
    {
        public function __construct(private readonly ExceptionHandler $original, private readonly ArrayObject $reported) {}

        public function report(Throwable $e)
        {
            $this->reported[] = $e;

            $this->original->report($e);
        }

        public function shouldReport(Throwable $e)
        {
            return $this->original->shouldReport($e);
        }

        public function render($request, Throwable $e)
        {
            return $this->original->render($request, $e);
        }

        public function renderForConsole($output, Throwable $e)
        {
            $this->original->renderForConsole($output, $e);
        }
    });
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

test('return options fall back to one-way pricing when the provider cannot quote a round-trip fare', function () {
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

    $option = collect($response->json('return_options'))->firstWhere('airline_code', 'YI');

    expect($option['pricing_method'])->toBe('oneway');

    $noFareReports = collect($state['reported'])
        ->filter(fn (Throwable $exception): bool => $exception instanceof NoFareAvailableException);

    expect($noFareReports)->toBeEmpty();
});
