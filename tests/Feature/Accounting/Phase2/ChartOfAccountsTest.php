<?php

use App\Models\Tenant;
use App\Services\Accounting\LedgerBootstrapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ─────────────────────────────────────────────────────────────────────────────
// Phase 2 — Chart of Accounts Setup
// Verifies that LedgerBootstrapService correctly seeds the CoA and sub-journals
// inside a tenant database for each agency type.
//
// Note: BootstrapTenantLedgerJob runs synchronously in the TenantCreated pipeline,
// so Tenant::create() already bootstraps the ledger. Tests assert the resulting state.
// ─────────────────────────────────────────────────────────────────────────────

function makePhase2Tenant(string $prefix, string $type = 'direct'): Tenant
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

test('direct agency ledger is bootstrapped with all required accounts', function () {
    $tenant = makePhase2Tenant('p2-direct', 'direct');

    $tenant->run(function () {
        $requiredCodes = [
            '1000', '1100', '1110', '1120',
            '1200', '1210', '1220', '1230', '1240',
            '1300', '1310', '1320',
            '2000', '2100', '2110', '2120', '2130', '2140',
            '2200', '2300', '2400', '2410', '2420', '2500', '2510', '2520',
            '3000', '3100', '3200', '3300',
            '4000', '4100', '4200', '4300', '4400', '4500', '4600', '4700',
            '5000', '5100', '5200', '5300', '5400', '5500',
            '6000', '6010', '6020', '6030', '6040', '6050', '6060',
            '7000', '7100', '7200', '7300', '7500',
            '8000', '8100', '8200', '8400',
            '1400', '1410', '1420',
        ];

        foreach ($requiredCodes as $code) {
            expect(DB::table('ledger_accounts')->where('code', $code)->exists())
                ->toBeTrue("Account code {$code} should exist in ledger_accounts");
        }
    });

    tenancy()->end();
    $tenant->delete();
});

test('network agency ledger is bootstrapped with network-specific accounts', function () {
    $tenant = makePhase2Tenant('p2-network', 'network');

    $tenant->run(function () {
        expect(DB::table('ledger_accounts')->where('code', '2200')->exists())->toBeTrue() // Network Agency Payable
            ->and(DB::table('ledger_accounts')->where('code', '8100')->exists())->toBeTrue() // Network Agency Settlement
            ->and(DB::table('ledger_accounts')->where('code', '4600')->exists())->toBeTrue(); // Network Commission Income
    });

    tenancy()->end();
    $tenant->delete();
});

test('merchant agency ledger is bootstrapped with merchant accounts', function () {
    $tenant = makePhase2Tenant('p2-merchant', 'merchant');

    $tenant->run(function () {
        expect(DB::table('ledger_accounts')->where('code', '1120')->exists())->toBeTrue() // Merchant Wallet
            ->and(DB::table('ledger_accounts')->where('code', '5500')->exists())->toBeTrue() // Merchant Wholesale Cost
            ->and(DB::table('ledger_accounts')->where('code', '8200')->exists())->toBeTrue(); // Merchant Settlement Clearing
    });

    tenancy()->end();
    $tenant->delete();
});

test('sub journals are created for all agency types', function () {
    $tenant = makePhase2Tenant('p2-journals', 'direct');

    $tenant->run(function () {
        foreach (['GEN', 'AIR', 'HTL', 'INS', 'ESM', 'STL'] as $code) {
            expect(DB::table('sub_journals')->where('code', $code)->exists())
                ->toBeTrue("Sub-journal {$code} should exist");
        }
    });

    tenancy()->end();
    $tenant->delete();
});

test('bootstrapping is idempotent — calling service again does not duplicate accounts', function () {
    $tenant = makePhase2Tenant('p2-idempotent', 'direct');

    $tenant->run(function () use ($tenant) {
        $countAfterFirst = DB::table('ledger_accounts')->count();

        // Call again — should be a no-op
        app(LedgerBootstrapService::class)->bootstrapForTenant($tenant);
        $countAfterSecond = DB::table('ledger_accounts')->count();

        expect($countAfterSecond)->toBe($countAfterFirst);
    });

    tenancy()->end();
    $tenant->delete();
});

test('coa is isolated per tenant', function () {
    $tenantA = makePhase2Tenant('p2-iso-a', 'direct');
    $tenantB = makePhase2Tenant('p2-iso-b', 'direct');

    $countA = $tenantA->run(fn () => DB::table('ledger_accounts')->count());
    tenancy()->end();

    $countB = $tenantB->run(fn () => DB::table('ledger_accounts')->count());
    tenancy()->end();

    expect($countA)->toBeGreaterThan(0)
        ->and($countB)->toBeGreaterThan(0)
        ->and($countA)->toBe($countB); // Same template → same count, different DBs

    $tenantA->delete();
    $tenantB->delete();
});

test('bootstrap result metadata is correct', function () {
    // Create a fresh tenant and manually call bootstrap to inspect the return value.
    // We use a tenant that was NOT created via the normal pipeline (no domain = no job).
    $tenant = Tenant::create([
        'id' => 'p2-meta-'.Str::random(4),
        'company_name' => 'Meta Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
        'type' => 'direct',
    ]);
    // No domain → BootstrapTenantLedgerJob still runs (it's in the pipeline on TenantCreated)
    // so we just verify the idempotent path returns sensible metadata.
    $tenant->run(function () use ($tenant) {
        $result = app(LedgerBootstrapService::class)->bootstrapForTenant($tenant);

        expect($result)->toHaveKeys(['created_root', 'added_accounts', 'total_required_accounts'])
            ->and($result['total_required_accounts'])->toBeGreaterThan(30);
    });

    tenancy()->end();
    $tenant->delete();
});
