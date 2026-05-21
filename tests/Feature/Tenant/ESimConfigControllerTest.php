<?php

use App\Models\Tenant;
use App\Models\Tenant\TenantEsimProvider;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'esim-cfg-'.Str::random(4),
        'company_name' => 'ESim Config Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $state['tenant'] = $tenant;
    $state['admin'] = $admin;
    $state['baseUrl'] = 'http://'.$tenant->domains->first()->domain;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('esim settings page renders with no providers configured', function () {
    global $state;

    $this->actingAs($state['admin']);

    $this->get($state['baseUrl'].route('settings.esim.index', [], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Settings/ESim')
            ->where('providers.0.provider_type', 'l2')
            ->where('providers.0.is_active', false)
            ->where('providers.0.status', 'not_configured')
        );
});

test('esim provider can be configured with api key and client secret', function () {
    global $state;

    $this->actingAs($state['admin']);

    $this->post($state['baseUrl'].route('settings.esim.store', [], false), [
        'provider_type' => 'l2',
        'name' => 'L2 Travel eSIM',
        'base_url' => 'https://l2travelesim.com/api/v2',
        'api_key' => 'test-api-key-123',
        'client_secret' => 'test-client-secret-456',
        'is_active' => true,
        'commission_esim' => 8.5,
        'initial_balance' => 500,
    ])->assertRedirect();

    $provider = TenantEsimProvider::query()->where('provider_type', 'l2')->firstOrFail();

    expect((string) $provider->name)->toBe('L2 Travel eSIM')
        ->and((bool) $provider->is_active)->toBeTrue()
        ->and((float) $provider->commission_esim)->toBe(8.5)
        ->and(data_get($provider->credentials, 'api_key'))->toBe('test-api-key-123')
        ->and(data_get($provider->credentials, 'client_secret'))->toBe('test-client-secret-456')
        ->and(data_get($provider->credentials, 'base_url'))->toBe('https://l2travelesim.com/api/v2');

    $wallet = $provider->getOrCreateCurrencyWallet('USD');
    expect(round((float) $wallet->balanceFloat, 2))->toBe(500.0);
});

test('esim settings page shows configured provider with balance', function () {
    global $state;

    $this->actingAs($state['admin']);

    $provider = TenantEsimProvider::query()->create([
        'provider_type' => 'l2',
        'name' => 'L2 Travel eSIM',
        'credentials' => [
            'base_url' => 'https://l2travelesim.com/api/v2',
            'api_key' => 'key-abc',
            'client_secret' => 'secret-xyz',
        ],
        'is_active' => true,
        'commission_esim' => 10,
        'currency' => 'USD',
    ]);

    $provider->getOrCreateCurrencyWallet('USD')->depositFloat(250, ['type' => 'test_fund']);

    $this->get($state['baseUrl'].route('settings.esim.index', [], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Settings/ESim')
            ->where('providers.0.is_active', true)
            ->where('providers.0.status', 'configured')
            ->where('providers.0.remaining_balance', 250)
            ->where('providers.0.api_key', 'key-abc')
            ->where('providers.0.client_secret', 'secret-xyz')
        );
});

test('esim provider deposit increases wallet balance', function () {
    global $state;

    $this->actingAs($state['admin']);

    $provider = TenantEsimProvider::query()->create([
        'provider_type' => 'l2',
        'name' => 'L2 Travel eSIM',
        'credentials' => [
            'base_url' => 'https://l2travelesim.com/api/v2',
            'api_key' => 'key-abc',
            'client_secret' => 'secret-xyz',
        ],
        'is_active' => true,
        'commission_esim' => 10,
        'currency' => 'USD',
    ]);

    $wallet = $provider->getOrCreateCurrencyWallet('USD');
    $wallet->depositFloat(100, ['type' => 'test_fund']);

    $this->post($state['baseUrl'].route('settings.esim.deposit', [], false), [
        'provider_type' => 'l2',
        'amount' => 150,
    ])->assertRedirect();

    expect(round((float) $wallet->fresh()->balanceFloat, 2))->toBe(250.0);
});

test('esim store rejects unsupported provider type', function () {
    global $state;

    $this->actingAs($state['admin']);

    $this->post($state['baseUrl'].route('settings.esim.store', [], false), [
        'provider_type' => 'unknown_provider',
        'name' => 'Unknown',
        'base_url' => 'https://example.com',
        'api_key' => 'key',
        'client_secret' => 'secret',
        'is_active' => true,
        'commission_esim' => 5,
    ])->assertRedirect();

    expect(TenantEsimProvider::query()->count())->toBe(0);
});

test('esim store does not add opening balance if wallet already funded', function () {
    global $state;

    $this->actingAs($state['admin']);

    $provider = TenantEsimProvider::query()->create([
        'provider_type' => 'l2',
        'name' => 'L2 Travel eSIM',
        'credentials' => [
            'base_url' => 'https://l2travelesim.com/api/v2',
            'api_key' => 'old-key',
            'client_secret' => 'old-secret',
        ],
        'is_active' => true,
        'commission_esim' => 5,
        'currency' => 'USD',
    ]);

    $wallet = $provider->getOrCreateCurrencyWallet('USD');
    $wallet->depositFloat(200, ['type' => 'existing_balance']);

    $this->post($state['baseUrl'].route('settings.esim.store', [], false), [
        'provider_type' => 'l2',
        'name' => 'L2 Travel eSIM',
        'base_url' => 'https://l2travelesim.com/api/v2',
        'api_key' => 'new-key',
        'client_secret' => 'new-secret',
        'is_active' => true,
        'commission_esim' => 8,
        'initial_balance' => 999,
    ])->assertRedirect();

    expect(round((float) $wallet->fresh()->balanceFloat, 2))->toBe(200.0);
});
