<?php

use Abivia\Ledger\Models\LedgerAccount;
use App\Actions\Finance\InitializeTenantLedger;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, RefreshDatabase::class);

/** @var array{tenant?: Tenant} $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'ledger-init-'.Str::random(4),
        'company_name' => 'Ledger Init Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    $state['tenant'] = $tenant;

    tenancy()->initialize($tenant);
});

afterEach(function () {
    global $state;

    tenancy()->end();

    if (isset($state['tenant'])) {
        $state['tenant']->delete();
    }
});

test('it initializes ledger root and required accounts for current tenant', function () {
    $result = app(InitializeTenantLedger::class)->execute('USD');

    expect($result['created_root'])->toBeTrue()
        ->and($result['total_required_accounts'])->toBeGreaterThan(0)
        ->and(LedgerAccount::hasRoot())->toBeTrue();

    foreach (['1300', '2200', '2200_ST', '2300', '3100', '3190', '6100'] as $code) {
        expect(LedgerAccount::query()->where('code', $code)->exists())->toBeTrue();
    }
});

test('it is idempotent and does not recreate existing ledger root or accounts', function () {
    app(InitializeTenantLedger::class)->execute('USD');

    $beforeCount = LedgerAccount::query()->count();

    $result = app(InitializeTenantLedger::class)->execute('USD');

    expect($result['created_root'])->toBeFalse()
        ->and($result['added_accounts'])->toBe(0)
        ->and(LedgerAccount::query()->count())->toBe($beforeCount);
});
