<?php

use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use Tests\AccountingTestCase;

function settingsTenant(): Tenant
{
    return Tenant::findOrFail(AccountingTestCase::sharedTenantId());
}

function settingsRoute(string $name, array|string|int $params = []): string
{
    $tenant = settingsTenant();

    return 'http://'.$tenant->domains->first()->domain.route($name, $params, false);
}

function settingsAdmin(): User
{
    $admin = null;
    settingsTenant()->run(function () use (&$admin) {
        $admin = User::query()->where('email', 'settings-admin@example.test')->first()
            ?? User::factory()->create([
                'email' => 'settings-admin@example.test',
                'role' => 'admin',
                'is_active' => true,
            ]);
    });
    tenancy()->end();

    return $admin;
}

test('accounting settings page loads provider thresholds using tenant_providers columns', function () {
    settingsTenant()->run(function () {
        TenantProvider::query()->firstOrCreate(
            [
                'provider_type' => 'videcom',
                'airline_code' => 'TST',
                'account_name' => 'LYD Account',
            ],
            [
                'airline_name' => 'Test Airways',
                'credentials' => ['endpoint' => 'https://example.test'],
                'is_active' => true,
            ],
        );
    });
    tenancy()->end();

    $this->actingAs(settingsAdmin())
        ->get(settingsRoute('accounting.settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounting/Settings/Index')
            ->has('providers')
            ->where('providers.0.name', 'Test Airways')
            ->where('providers.0.type', 'videcom'));
});
