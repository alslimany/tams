<?php

use App\Models\Tenant;
use App\Models\Tenant\Booking;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Ticket;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Str;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

uses(MockeryPHPUnitIntegration::class);

beforeEach(function () {
    $tenant = Tenant::create([
        'id' => 'role-'.Str::random(4),
        'company_name' => 'Role Travel',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    $this->tenant = $tenant;

    tenancy()->initialize($tenant);

    $this->admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->agent = User::factory()->create([
        'role' => 'agent',
        'is_active' => true,
    ]);

    $this->provider = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'Default',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    $customer = Customer::create([
        'first_name' => 'Sara',
        'last_name' => 'Noor',
        'email' => 'sara@example.com',
        'phone' => '123',
    ]);

    $this->booking = Booking::create([
        'customer_id' => $customer->id,
        'pnr' => 'ROLE01',
        'tenant_provider_id' => $this->provider->id,
        'status' => 'ticketed',
        'total_price' => 450,
        'currency' => 'LYD',
        'created_by' => $this->admin->id,
    ]);

    $this->ticket = Ticket::create([
        'booking_id' => $this->booking->id,
        'ticket_number' => '1234567890123',
        'status' => 'issued',
        'issued_at' => now(),
    ]);
});

afterEach(function () {
    tenancy()->end();
    $this->tenant->delete();
});

test('agent cannot access admin-only user management', function () {
    $url = 'http://'.$this->tenant->domains->first()->domain.route('users.index', [], false);

    $this->actingAs($this->agent)
        ->get($url)
        ->assertForbidden();
});

test('manager cannot access admin-only user management', function () {
    $url = 'http://'.$this->tenant->domains->first()->domain.route('users.index', [], false);

    $this->actingAs($this->manager)
        ->get($url)
        ->assertForbidden();
});

test('agent cannot issue or modify tickets', function () {
    $baseUrl = 'http://'.$this->tenant->domains->first()->domain;

    $this->actingAs($this->agent)
        ->post($baseUrl.route('tickets.refund', ['booking' => $this->booking, 'ticket' => $this->ticket], false))
        ->assertForbidden();
});

test('manager can access ticketing routes', function () {
    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('refund')->andReturn('REFUND OK');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $baseUrl = 'http://'.$this->tenant->domains->first()->domain;

    $this->actingAs($this->manager)
        ->post($baseUrl.route('tickets.refund', ['booking' => $this->booking, 'ticket' => $this->ticket], false))
        ->assertRedirect();

    expect($this->ticket->fresh()->status)->toBe('refunded');
});
