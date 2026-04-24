<?php

use Abivia\Ledger\Models\LedgerAccount;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('ledger initialize command sets up ledger for all tenants', function () {
    $tenantA = Tenant::create([
        'id' => 'ledger-cmd-a-'.Str::random(4),
        'company_name' => 'Ledger Cmd A',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);
    $tenantA->domains()->create(['domain' => $tenantA->id.'.localhost']);

    $tenantB = Tenant::create([
        'id' => 'ledger-cmd-b-'.Str::random(4),
        'company_name' => 'Ledger Cmd B',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);
    $tenantB->domains()->create(['domain' => $tenantB->id.'.localhost']);

    $this->artisan('ledger:initialize-tenants --currency=USD')
        ->assertExitCode(0);

    expect($tenantA->run(fn () => LedgerAccount::hasRoot()))->toBeTrue()
        ->and($tenantB->run(fn () => LedgerAccount::hasRoot()))->toBeTrue();
});
