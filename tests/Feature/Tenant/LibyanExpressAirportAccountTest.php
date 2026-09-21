<?php

use App\Http\Controllers\Tenant\AirlineConfigController;
use App\Http\Controllers\Tenant\BookingController;
use App\Models\Tenant;
use App\Models\TenantProvider;
use Illuminate\Support\Collection;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function (): void {
    global $state;

    $tenant = Tenant::create([
        'id' => 'lb-airport-filter-'.Illuminate\Support\Str::random(4),
        'company_name' => 'LB Airport Filter Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    tenancy()->initialize($tenant);

    $state['tenant'] = $tenant;
});

afterEach(function (): void {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

function invokeProviderAirportFilter(Collection $providers, string $origin, string $destination): Collection
{
    $method = new \ReflectionMethod(BookingController::class, 'filterProvidersByAccountAirports');
    $method->setAccessible(true);

    /** @var Collection<int, TenantProvider> $result */
    $result = $method->invoke(app(BookingController::class), $providers, $origin, $destination);

    return $result;
}

function makeLbProvider(string $accountName, string $currency, array $airports = []): TenantProvider
{
    return TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'LB',
        'airline_name' => 'Libyan Express',
        'account_name' => $accountName,
        'credentials' => [
            'mode' => 'api',
            'token' => 'test-token',
            'base_url' => 'https://customer2.videcom.com/LibyanExpress',
            'currency' => $currency,
            'airports' => $airports,
            'airline_code' => 'LB',
        ],
        'is_active' => true,
    ]);
}

test('libyan express catalog exposes lyd default and eur mla fco accounts', function () {
    $method = new \ReflectionMethod(AirlineConfigController::class, 'getSupportedAirlines');
    $method->setAccessible(true);

    /** @var list<array<string, mixed>> $airlines */
    $airlines = $method->invoke(app(AirlineConfigController::class));

    $libyanExpress = collect($airlines)->firstWhere('id', 'LB');

    expect($libyanExpress)->not->toBeNull()
        ->and($libyanExpress['accounts'])->toHaveCount(2)
        ->and($libyanExpress['accounts'][0])->toMatchArray([
            'name' => 'Default Account',
            'currency' => 'LYD',
        ])
        ->and($libyanExpress['accounts'][1])->toMatchArray([
            'name' => 'EUR Account',
            'currency' => 'EUR',
            'airports' => ['MLA', 'FCO'],
        ]);
});

test('eur libyan express account is selected for mla routes and lyd account is excluded', function () {
    $providers = collect([
        makeLbProvider('Default Account', 'LYD'),
        makeLbProvider('EUR Account', 'EUR', ['MLA', 'FCO']),
    ]);

    $filtered = invokeProviderAirportFilter($providers, 'MLA', 'TIP');

    expect($filtered)->toHaveCount(1)
        ->and($filtered->first()?->account_name)->toBe('EUR Account')
        ->and($filtered->first()?->credentials['currency'])->toBe('EUR');
});

test('lyd libyan express account remains available for non eur airport routes', function () {
    $providers = collect([
        makeLbProvider('Default Account', 'LYD'),
        makeLbProvider('EUR Account', 'EUR', ['MLA', 'FCO']),
    ]);

    $filtered = invokeProviderAirportFilter($providers, 'TIP', 'IST');

    expect($filtered)->toHaveCount(1)
        ->and($filtered->first()?->account_name)->toBe('Default Account')
        ->and($filtered->first()?->credentials['currency'])->toBe('LYD');
});

test('eur libyan express account is selected when destination is fco', function () {
    $providers = collect([
        makeLbProvider('Default Account', 'LYD'),
        makeLbProvider('EUR Account', 'EUR', ['MLA', 'FCO']),
    ]);

    $filtered = invokeProviderAirportFilter($providers, 'TIP', 'FCO');

    expect($filtered)->toHaveCount(1)
        ->and($filtered->first()?->account_name)->toBe('EUR Account');
});
