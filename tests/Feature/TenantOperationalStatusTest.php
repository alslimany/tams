<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

test('frozen tenants cannot access the tenant dashboard', function () {
    $tenant = Tenant::create([
        'id' => 'frozen-'.Str::random(4),
        'company_name' => 'Frozen Travel',
        'status' => 'frozen',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $url = 'http://'.$tenant->domains->first()->domain.route('dashboard', [], false);

    $response = $this->actingAs($user)->get($url);

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('email');

    tenancy()->end();
    $tenant->delete();
});

test('suspended tenants cannot access the tenant dashboard', function () {
    $tenant = Tenant::create([
        'id' => 'suspended-'.Str::random(4),
        'company_name' => 'Suspended Travel',
        'status' => 'suspended',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $url = 'http://'.$tenant->domains->first()->domain.route('dashboard', [], false);

    $response = $this->actingAs($user)->get($url);

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('email');

    tenancy()->end();
    $tenant->delete();
});
