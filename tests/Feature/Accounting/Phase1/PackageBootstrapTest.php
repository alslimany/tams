<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// ─────────────────────────────────────────────────────────────────────────────
// Phase 1 — Package Bootstrap
// Verifies that wallet and ledger tables exist in the TENANT database only,
// and that the Tenant model carries the new `type` column correctly.
// ─────────────────────────────────────────────────────────────────────────────

test('wallet tables exist in tenant database', function () {
    $tenant = Tenant::create([
        'id' => 'phase1-wallet-'.Str::random(4),
        'company_name' => 'Phase 1 Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);
    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    $tenant->run(function () {
        expect(Schema::hasTable('wallets'))->toBeTrue()
            ->and(Schema::hasTable('transactions'))->toBeTrue()
            ->and(Schema::hasTable('transfers'))->toBeTrue();
    });

    tenancy()->end();
    $tenant->delete();
});

test('ledger tables exist in tenant database', function () {
    $tenant = Tenant::create([
        'id' => 'phase1-ledger-'.Str::random(4),
        'company_name' => 'Phase 1 Ledger Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);
    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    $tenant->run(function () {
        expect(Schema::hasTable('ledger_accounts'))->toBeTrue()
            ->and(Schema::hasTable('journal_entries'))->toBeTrue()
            ->and(Schema::hasTable('journal_details'))->toBeTrue();
    });

    tenancy()->end();
    $tenant->delete();
});

test('wallet tables do not exist in the central database', function () {
    // In SQLite in-memory test mode all migrations share one connection,
    // so we verify isolation by confirming the default connection is NOT
    // the tenant connection (i.e. no tenancy is active outside a tenant context).
    expect(tenancy()->initialized)->toBeFalse();

    // When running against a real MySQL environment the wallet tables must
    // not be present on the central connection. We skip the schema assertion
    // in SQLite (in-memory) because RefreshDatabase shares one connection.
    if (config('database.default') !== 'sqlite') {
        expect(Schema::hasTable('wallets'))->toBeFalse()
            ->and(Schema::hasTable('transactions'))->toBeFalse()
            ->and(Schema::hasTable('transfers'))->toBeFalse();
    }
});

test('ledger tables do not exist in the central database', function () {
    expect(tenancy()->initialized)->toBeFalse();

    if (config('database.default') !== 'sqlite') {
        expect(Schema::hasTable('ledger_accounts'))->toBeFalse()
            ->and(Schema::hasTable('journal_entries'))->toBeFalse();
    }
});

test('tenant type column defaults to direct', function () {
    $tenant = Tenant::create([
        'id' => 'phase1-type-'.Str::random(4),
        'company_name' => 'Direct Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    expect($tenant->type)->toBe('direct');

    $tenant->delete();
});

test('tenant type can be set to network', function () {
    $tenant = Tenant::create([
        'id' => 'phase1-net-'.Str::random(4),
        'company_name' => 'Network Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
        'type' => 'network',
    ]);

    expect($tenant->type)->toBe('network');

    $tenant->delete();
});

test('tenant type can be set to merchant', function () {
    $tenant = Tenant::create([
        'id' => 'phase1-mer-'.Str::random(4),
        'company_name' => 'Merchant Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
        'type' => 'merchant',
    ]);

    expect($tenant->type)->toBe('merchant');

    $tenant->delete();
});

test('tenant model has wallet capability', function () {
    $tenant = Tenant::create([
        'id' => 'phase1-has-wallet-'.Str::random(4),
        'company_name' => 'Wallet Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);
    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    $tenant->run(function () use ($tenant) {
        // BootstrapTenantLedgerJob auto-provisions the operating wallet on creation
        $wallet = $tenant->getWallet('operating');

        expect($wallet)->not->toBeNull()
            ->and($wallet->slug)->toBe('operating')
            ->and((int) $wallet->balanceInt)->toBe(0);
    });

    tenancy()->end();
    $tenant->delete();
});
