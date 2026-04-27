<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $tenant = Tenant::create([
        'id' => 'settlement-cmd-'.Str::random(4),
        'company_name' => 'Settlement Command Test',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    tenancy()->initialize($tenant);

    $this->manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $this->manager->id,
        'number' => 'ORD-SETTLE-001',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 300,
        'tax_total' => 50,
        'grand_total' => 350,
        'amount_paid' => 350,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'default_agency_supply',
        'contact' => ['email' => 'test@example.com'],
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'ticket',
        'product_subtype' => 'economy',
        'provider' => 'videcom',
        'provider_reference' => 'SETTLE001',
        'item_details' => [
            'financial_source' => 'master_agency_supply',
            'default_agency_tenant_id' => 'default-agency-1',
        ],
        'price' => 300,
        'net_fare' => 300,
        'taxes' => [],
        'total_tax' => 50,
        'total' => 350,
        'total_amount' => 350,
        'currency' => 'LYD',
        'status' => 'issued',
        'commission_percent' => 0,
        'commission_amount' => 0,
        'net_after_commission' => 300,
        'agent_commission' => 30,
        'net_commission' => 30,
        'paid' => 350,
        'remaining' => 0,
    ]);
});

afterEach(function () {
    tenancy()->end();
});

test('finance:settlement-report lists payable commissions for master-agency supply items', function () {
    $this->artisan('finance:settlement-report', ['tenantId' => tenant('id'), '--days' => 7])
        ->expectsOutputToContain('default-agency-1')
        ->expectsOutputToContain('LYD')
        ->assertSuccessful();
});
