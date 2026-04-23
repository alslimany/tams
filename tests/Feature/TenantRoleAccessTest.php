<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

uses(MockeryPHPUnitIntegration::class);

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'role-'.Str::random(4),
        'company_name' => 'Role Travel',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    $state['tenant'] = $tenant;

    tenancy()->initialize($tenant);

    $state['admin'] = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $state['manager'] = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $state['agent'] = User::factory()->create([
        'role' => 'agent',
        'is_active' => true,
    ]);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('agent cannot access admin-only user management', function () {
    global $state;

    $url = 'http://'.$state['tenant']->domains->first()->domain.route('users.index', [], false);

    $this->actingAs($state['agent'])
        ->get($url)
        ->assertForbidden();
});

test('manager cannot access admin-only user management', function () {
    global $state;

    $url = 'http://'.$state['tenant']->domains->first()->domain.route('users.index', [], false);

    $this->actingAs($state['manager'])
        ->get($url)
        ->assertForbidden();
});
