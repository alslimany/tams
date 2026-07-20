<?php

use App\Models\Tenant;
use App\Models\User;
use Tests\AccountingTestCase;

function walletsTenant(): Tenant
{
    return Tenant::findOrFail(AccountingTestCase::sharedTenantId());
}

function walletsRoute(string $name, array|string|int $params = []): string
{
    $tenant = walletsTenant();

    return 'http://'.$tenant->domains->first()->domain.route($name, $params, false);
}

function walletsAdmin(): User
{
    $admin = null;
    walletsTenant()->run(function () use (&$admin) {
        $admin = User::query()->where('email', 'wallets-admin@example.test')->first()
            ?? User::factory()->create([
                'email' => 'wallets-admin@example.test',
                'role' => 'admin',
                'is_active' => true,
            ]);
    });
    tenancy()->end();

    return $admin;
}

test('accounting all wallets page loads without querying missing confirmed_at column', function () {
    $this->actingAs(walletsAdmin())
        ->get(walletsRoute('accounting.wallets.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Accounting/Wallets/Index')->has('wallets'));
});

test('accounting provider wallets page loads without querying missing confirmed_at column', function () {
    $this->actingAs(walletsAdmin())
        ->get(walletsRoute('accounting.providers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Accounting/Providers/Index')->has('providers'));
});
