<?php

use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Services\Wallet\WalletProvisioningService;
use Bavix\Wallet\Models\Wallet;
use Illuminate\Support\Str;

// ─────────────────────────────────────────────────────────────────────────────
// Phase 3 — Wallet Provisioning
// Verifies that WalletProvisioningService creates the correct named wallets
// with the correct slugs and ledger_account meta for each tenant/provider type.
// ─────────────────────────────────────────────────────────────────────────────

function makePhase3Tenant(string $prefix, string $type = 'direct'): Tenant
{
    $tenant = Tenant::create([
        'id' => $prefix.'-'.Str::random(4),
        'company_name' => ucfirst($type).' Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
        'type' => $type,
    ]);
    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    return $tenant;
}

test('direct agency operating wallet is provisioned on tenant creation', function () {
    $tenant = makePhase3Tenant('p3-direct', 'direct');

    $tenant->run(function () use ($tenant) {
        $wallet = $tenant->getWallet('operating');

        expect($wallet)->not->toBeNull()
            ->and($wallet->slug)->toBe('operating')
            ->and($wallet->meta['ledger_account'])->toBe('1110')
            ->and($wallet->meta['type'])->toBe('operating');
    });

    tenancy()->end();
    $tenant->delete();
});

test('merchant tenant gets merchant wallet instead of operating wallet', function () {
    $tenant = makePhase3Tenant('p3-merchant', 'merchant');

    $tenant->run(function () use ($tenant) {
        $merchantWallet = $tenant->getWallet('merchant');
        $operatingWallet = $tenant->getWallet('operating');

        expect($merchantWallet)->not->toBeNull()
            ->and($merchantWallet->slug)->toBe('merchant')
            ->and($merchantWallet->meta['ledger_account'])->toBe('1120')
            ->and($operatingWallet)->toBeNull();
    });

    tenancy()->end();
    $tenant->delete();
});

test('network agency gets operating wallet', function () {
    $tenant = makePhase3Tenant('p3-network', 'network');

    $tenant->run(function () use ($tenant) {
        $wallet = $tenant->getWallet('operating');

        expect($wallet)->not->toBeNull()
            ->and($wallet->meta['ledger_account'])->toBe('1110');
    });

    tenancy()->end();
    $tenant->delete();
});

test('operating wallet balance starts at zero', function () {
    $tenant = makePhase3Tenant('p3-zero', 'direct');

    $tenant->run(function () use ($tenant) {
        $wallet = $tenant->getWallet('operating');

        expect((int) $wallet->balanceInt)->toBe(0);
    });

    tenancy()->end();
    $tenant->delete();
});

test('airline provider wallet is provisioned with correct slug and meta', function () {
    $tenant = makePhase3Tenant('p3-air-prov', 'direct');

    $tenant->run(function () {
        $provider = TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'AA',
            'airline_name' => 'Test Airline',
            'account_name' => 'test-account',
            'credentials' => '{}',
            'is_active' => true,
        ]);

        app(WalletProvisioningService::class)->provisionProviderWallet($provider);

        $wallet = $provider->getWallet('airline-provider');

        expect($wallet)->not->toBeNull()
            ->and($wallet->slug)->toBe('airline-provider')
            ->and($wallet->meta['ledger_account'])->toBe('1210')
            ->and($wallet->meta['type'])->toBe('provider');
    });

    tenancy()->end();
    $tenant->delete();
});

test('provisionForTenant is idempotent — calling twice does not duplicate wallets', function () {
    $tenant = makePhase3Tenant('p3-idem', 'direct');

    $tenant->run(function () use ($tenant) {
        $service = app(WalletProvisioningService::class);

        // Pipeline already ran once; call again manually
        $service->provisionForTenant($tenant);

        $walletCount = Wallet::where('holder_type', Tenant::class)
            ->where('holder_id', $tenant->id)
            ->count();

        expect($walletCount)->toBe(1); // only one 'operating' wallet
    });

    tenancy()->end();
    $tenant->delete();
});

test('provider wallet provisioning is idempotent', function () {
    $tenant = makePhase3Tenant('p3-prov-idem', 'direct');

    $tenant->run(function () {
        $provider = TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'BB',
            'airline_name' => 'Test Airline 2',
            'account_name' => 'test-account-2',
            'credentials' => '{}',
            'is_active' => true,
        ]);

        $service = app(WalletProvisioningService::class);
        $service->provisionProviderWallet($provider);
        $service->provisionProviderWallet($provider); // second call — no-op

        expect(Wallet::where('holder_type', TenantProvider::class)
            ->where('holder_id', $provider->id)
            ->count())->toBe(1);
    });

    tenancy()->end();
    $tenant->delete();
});

test('deposit to operating wallet increases balance', function () {
    $tenant = makePhase3Tenant('p3-deposit', 'direct');

    $tenant->run(function () use ($tenant) {
        $wallet = $tenant->getWallet('operating');
        $wallet->depositFloat(5000.000, ['tx_type' => 'deposit']);

        expect((float) $wallet->balanceFloat)->toBe(5000.0);
    });

    tenancy()->end();
    $tenant->delete();
});

test('cannot withdraw more than wallet balance', function () {
    $tenant = makePhase3Tenant('p3-withdraw', 'direct');

    $tenant->run(function () use ($tenant) {
        $wallet = $tenant->getWallet('operating');
        $wallet->depositFloat(100.000);

        expect($wallet->canWithdrawFloat(200.000))->toBeFalse()
            ->and($wallet->canWithdrawFloat(50.000))->toBeTrue();
    });

    tenancy()->end();
    $tenant->delete();
});
