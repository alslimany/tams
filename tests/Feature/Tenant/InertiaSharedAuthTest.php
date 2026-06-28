<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'inertia-'.Str::random(4),
        'company_name' => 'Inertia Auth Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    $state['tenant'] = $tenant;
    $state['baseUrl'] = 'http://'.$tenant->domains->first()->domain;

    tenancy()->initialize($tenant);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('inertia shares authenticated tenant user with role after tenancy middleware runs', function () {
    global $state;

    $user = User::factory()->create([
        'email' => 'admin@admin.com',
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get($state['baseUrl'].route('dashboard', [], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.id', $user->id)
            ->where('auth.user.email', 'admin@admin.com')
            ->where('auth.user.role', 'admin')
            ->where('tenant.id', $state['tenant']->id)
        );
});

test('inertia does not share tenant user role for non-admin agents', function () {
    global $state;

    $user = User::factory()->create([
        'role' => 'agent',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get($state['baseUrl'].route('dashboard', [], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.role', 'agent')
        );
});
