<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $tenant = Tenant::create([
        'id' => 'wallet-mw-'.Str::random(4),
        'company_name' => 'Wallet Middleware Test',
        'status' => 'active',
        'subscription_status' => 'trial',
        'use_own_airline_credentials' => false,
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    tenancy()->initialize($tenant);

    $this->manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);
});

afterEach(function () {
    tenancy()->end();
});

test('wallet balance middleware blocks booking when balance is insufficient', function () {
    $this->actingAs($this->manager);

    $baseUrl = 'http://'.tenant()->domains->first()->domain;

    // Wallet has no funds — attempt to book with grand_total > 0.
    $response = $this->post($baseUrl.route('flights.store', [], false), [
        'currency' => 'LYD',
        'grand_total' => 500,
    ]);

    $response->assertRedirect()
        ->assertSessionHasErrors('wallet');
});

test('wallet balance middleware allows booking when balance is sufficient', function () {
    $this->actingAs($this->manager);

    $wallet = $this->manager->getOrCreateCurrencyWallet('LYD');
    $wallet->deposit(100000, ['type' => 'initial_fund']); // 1000 LYD

    $baseUrl = 'http://'.tenant()->domains->first()->domain;

    // This will fail at the controller level (missing booking data) but should
    // pass the middleware — i.e., no wallet error in session.
    $response = $this->post($baseUrl.route('flights.store', [], false), [
        'currency' => 'LYD',
        'grand_total' => 500,
    ]);

    // Should NOT have wallet balance error (may have other validation errors).
    $response->assertSessionDoesntHaveErrors('wallet');
});

test('wallet balance middleware skips for tenants with own credentials', function () {
    // Update tenant to use own airline credentials.
    tenant()->update(['use_own_airline_credentials' => true]);

    $this->actingAs($this->manager);

    $baseUrl = 'http://'.tenant()->domains->first()->domain;

    // No wallet funds, but tenant uses own credentials — should skip check.
    $response = $this->post($baseUrl.route('flights.store', [], false), [
        'currency' => 'LYD',
        'grand_total' => 500,
    ]);

    // Should NOT have wallet balance error.
    $response->assertSessionDoesntHaveErrors('wallet');
});

test('wallet balance middleware skips for non-booking routes', function () {
    $this->actingAs($this->manager);

    $baseUrl = 'http://'.tenant()->domains->first()->domain;

    // GET request to reports — middleware should not interfere.
    $response = $this->get($baseUrl.route('reports.sales', [], false));

    // Should not have wallet errors (may redirect if not authenticated properly).
    $response->assertSessionDoesntHaveErrors('wallet');
});
