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
        'id' => 'airline-config-'.Str::random(4),
        'company_name' => 'Airline Config Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    $state['tenant'] = $tenant;

    tenancy()->initialize($tenant);

    $state['user'] = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $providerMock = \Mockery::mock();

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

test('admin can store videcom commission rates with provider credentials', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->post($baseUrl.route('settings.airlines.store', [], false), [
        'provider_type' => 'videcom',
        'airline_code' => 'BM',
        'airline_name' => 'Medsky Airline',
        'account_name' => 'Default Account',
        'mode' => 'session',
        'username' => 'AGENT123',
        'password' => 'top-secret',
        'token' => '',
        'base_url' => 'https://customer3.videcom.com/Medsky',
        'currency' => 'LYD',
        'airports' => ['IST', 'MJI', 'BEN'],
        'domestic_commission_rate' => '5.50',
        'international_commission_rate' => '9.75',
    ])->assertRedirect()->assertSessionHas('success');

    $provider = TenantProvider::query()
        ->where('airline_code', 'BM')
        ->where('account_name', 'Default Account')
        ->first();

    expect($provider)->not->toBeNull()
        ->and((float) $provider->domestic_commission_rate)->toBe(5.5)
        ->and((float) $provider->international_commission_rate)->toBe(9.75)
        ->and(data_get($provider->credentials, 'mode'))->toBe('session')
        ->and(data_get($provider->credentials, 'username'))->toBe('AGENT123');
});
