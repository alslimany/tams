<?php

use App\Models\Tenant;
use App\Models\Tenant\CoaSetting;
use App\Models\User;
use App\Services\Accounting\AccountNumberingService;
use Tests\AccountingTestCase;

// ─────────────────────────────────────────────────────────────────────────────
// Upgrade v2 — CoA auto-numbering + management guards.
// Covers plan §7 "CoA Management" checklist items.
// ─────────────────────────────────────────────────────────────────────────────

function uv2Tenant(): Tenant
{
    return Tenant::findOrFail(AccountingTestCase::sharedTenantId());
}

function uv2Route(string $name, array|string|int $params = []): string
{
    $tenant = uv2Tenant();

    return 'http://'.$tenant->domains->first()->domain.route($name, $params, false);
}

function uv2Admin(): User
{
    $admin = null;
    uv2Tenant()->run(function () use (&$admin) {
        $admin = User::query()->where('email', 'uv2-admin@example.test')->first()
            ?? User::factory()->create([
                'email' => 'uv2-admin@example.test',
                'role' => 'admin',
                'is_active' => true,
            ]);
    });
    tenancy()->end();

    return $admin;
}

test('creating a child account auto-suggests the next code within the parent range', function () {
    uv2Tenant()->run(function () {
        // 1400 Inventory has children 1410 and 1420 from the template.
        $code = app(AccountNumberingService::class)->nextAvailableCode('1400');

        expect($code)->toBe('1430');
    });
    tenancy()->end();
});

test('creating a top-level purchase account auto-suggests the next code in the 6xxx range', function () {
    uv2Tenant()->run(function () {
        $code = app(AccountNumberingService::class)->nextTopLevelCode('purchase');

        expect((int) $code)->toBeGreaterThanOrEqual(6000)
            ->and((int) $code)->toBeLessThanOrEqual(6999);
    });
    tenancy()->end();
});

test('next-code endpoint returns a suggestion for a parent account', function () {
    $this->actingAs(uv2Admin())
        ->get(uv2Route('accounting.ledger.coa.next-code', ['parent' => '1400']))
        ->assertOk()
        ->assertJson(['code' => '1430']);
});

test('duplicate account codes are rejected with a validation error', function () {
    $this->actingAs(uv2Admin())
        ->post(uv2Route('accounting.ledger.coa.store'), [
            'code' => '1420', // already exists in the template
            'name' => 'Duplicate Inventory',
            'type' => 'asset',
        ])
        ->assertSessionHasErrors([
            'code' => 'Account code 1420 already exists',
        ]);
});

test('duplicate account codes such as 7500 are rejected with a clear message', function () {
    $this->actingAs(uv2Admin())
        ->post(uv2Route('accounting.ledger.coa.store'), [
            'code' => '7500',
            'name' => 'Duplicate Commission Expense',
            'type' => 'expense',
            'parent' => '7000',
        ])
        ->assertSessionHasErrors([
            'code' => 'Account code 7500 already exists',
        ]);
});

test('system accounts cannot be deleted', function () {
    $this->actingAs(uv2Admin())
        ->delete(uv2Route('accounting.ledger.coa.destroy', '1420'))
        ->assertSessionHas('error');

    uv2Tenant()->run(function () {
        expect(\Abivia\Ledger\Models\LedgerAccount::where('code', '1420')->exists())->toBeTrue();
    });
    tenancy()->end();
});

test('a created account is mirrored into coa_settings and can be deactivated', function () {
    $admin = uv2Admin();
    $code = '743'.mt_rand(10, 99);

    $this->actingAs($admin)
        ->post(uv2Route('accounting.ledger.coa.store'), [
            'code' => $code,
            'name' => 'UV2 Test Expense',
            'type' => 'expense',
            'parent' => '7000',
        ])
        ->assertSessionHas('success');

    uv2Tenant()->run(function () use ($code) {
        $setting = CoaSetting::where('code', $code)->first();

        expect($setting)->not->toBeNull()
            ->and($setting->is_system)->toBeFalse()
            ->and($setting->is_active)->toBeTrue()
            ->and($setting->parent_code)->toBe('7000');
    });
    tenancy()->end();

    $this->actingAs($admin)
        ->put(uv2Route('accounting.ledger.coa.update', $code), [
            'name' => 'UV2 Test Expense',
            'is_active' => false,
        ])
        ->assertSessionHas('success');

    uv2Tenant()->run(function () use ($code) {
        expect(CoaSetting::where('code', $code)->value('is_active'))->toBeFalsy();
    });
    tenancy()->end();
});
