<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'dashboard-'.Str::random(4),
        'company_name' => 'Dashboard Agency',
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

test('guests are redirected to the login page', function () {
    global $state;

    $response = $this->get($state['baseUrl'].route('dashboard', [], false));

    $response->assertRedirect($state['baseUrl'].route('login', [], false));
});

test('authenticated users can visit the dashboard', function () {
    global $state;

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $this->actingAs($user);

    $response = $this->get($state['baseUrl'].route('dashboard', [], false));

    $response->assertSuccessful();
});

test('authenticated users can visit the dashboard with wallet transactions', function () {
    global $state;

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $wallet = $user->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(100);

    $this->actingAs($user);

    $response = $this->get($state['baseUrl'].route('dashboard', [], false));

    $response->assertSuccessful();
});
