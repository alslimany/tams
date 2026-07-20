<?php

use App\Models\Tenant;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\User;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'ins-wallet-'.Str::random(4),
        'company_name' => 'Insurance Wallet Tenant',
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
    $state['baseUrl'] = 'http://'.$tenant->domains->first()->domain;
    $state['admin'] = $admin;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('insurance provider configuration can set opening wallet balance', function () {
    global $state;

    $this->actingAs($state['admin']);

    $response = $this->post($state['baseUrl'].route('settings.insurance.store', [], false), [
        'provider_type' => 'albaraka',
        'name' => 'Al Baraka Insurance',
        'base_url' => 'https://tameen.webapi.ly',
        'token' => 'test-token',
        'is_active' => true,
        'commission_compulsory' => 5,
        'commission_travel' => 10,
        'commission_orange' => 8,
        'initial_balance' => 450,
    ]);

    $response->assertRedirect();

    $provider = TenantInsuranceProvider::query()->where('provider_type', 'albaraka')->firstOrFail();
    $wallet = $provider->getOrCreateCurrencyWallet('LYD');

    expect(round((float) $wallet->balanceFloat, 2))->toBe(450.0);

    expect(WalletTransaction::query()
        ->where('wallet_id', $wallet->id)
        ->where('type', 'deposit')
        ->exists())->toBeTrue();
});

test('insurance provider wallet supports manual deposits', function () {
    global $state;

    $provider = TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Al Baraka Insurance',
        'credentials' => [
            'base_url' => 'https://tameen.webapi.ly',
            'token' => 'test-token',
        ],
        'is_active' => true,
        'commission_compulsory' => 5,
        'commission_travel' => 10,
        'commission_orange' => 8,
    ]);

    $wallet = $provider->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(100, ['type' => 'seed_balance']);

    $this->actingAs($state['admin']);

    $response = $this->post($state['baseUrl'].route('settings.insurance.deposit', [], false), [
        'provider_type' => 'albaraka',
        'currency' => 'LYD',
        'amount' => 75,
    ]);

    $response->assertRedirect();

    $provider->refresh();
    $wallet->refresh();

    expect(round((float) $wallet->balanceFloat, 2))->toBe(175.0);

    expect(WalletTransaction::query()
        ->where('wallet_id', $wallet->id)
        ->where('type', 'deposit')
        ->count())->toBeGreaterThanOrEqual(2);
});

test('insurance settings page returns decrypted bearer token for configured provider', function () {
    global $state;

    TenantInsuranceProvider::query()->updateOrCreate(
        ['provider_type' => 'albaraka'],
        [
            'name' => 'Al Baraka Insurance',
            'credentials' => [
                'base_url' => 'https://tameen.webapi.ly',
                'token' => 'secret-bearer-token',
            ],
            'is_active' => true,
            'commission_compulsory' => 5,
            'commission_travel' => 10,
            'commission_orange' => 8,
        ],
    );

    $this->actingAs($state['admin'])
        ->get($state['baseUrl'].route('settings.insurance.index', [], false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Settings/Insurance')
            ->where('providers', fn ($providers) => collect($providers)
                ->firstWhere('provider_type', 'albaraka')['token'] === 'secret-bearer-token')
        );
});

test('legacy insurance credentials with encrypted token field are decrypted for display', function () {
    global $state;

    $provider = TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Al Baraka Insurance',
        'is_active' => true,
        'commission_compulsory' => 5,
        'commission_travel' => 10,
        'commission_orange' => 8,
    ]);

    \Illuminate\Support\Facades\DB::table('tenant_insurance_providers')
        ->where('id', $provider->id)
        ->update([
            'credentials' => json_encode([
                'base_url' => 'https://tameen.webapi.ly',
                'token' => encrypt('legacy-encrypted-token'),
            ], JSON_THROW_ON_ERROR),
        ]);

    $this->actingAs($state['admin'])
        ->get($state['baseUrl'].route('settings.insurance.index', [], false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('providers', fn ($providers) => collect($providers)
                ->firstWhere('provider_type', 'albaraka')['token'] === 'legacy-encrypted-token')
        );
});
