<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $tenant = Tenant::create([
        'id' => 'recon-cmd-'.Str::random(4),
        'company_name' => 'Reconcile Command Test',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    tenancy()->initialize($tenant);

    $this->admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
});

afterEach(function () {
    tenancy()->end();
});

test('finance:reconcile command runs successfully with no activity', function () {
    $this->artisan('finance:reconcile', ['tenantId' => tenant('id')])
        ->expectsOutput('Reconciling tenant: '.tenant('id'))
        ->assertSuccessful();
});

test('finance:reconcile reports balanced when orders match wallet withdrawals', function () {
    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $this->admin->id,
        'number' => 'ORD-RECON-001',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 300,
        'tax_total' => 50,
        'grand_total' => 350,
        'amount_paid' => 350,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'wallet',
        'contact' => ['email' => 'test@example.com'],
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'ticket',
        'product_subtype' => 'economy',
        'provider' => 'videcom',
        'provider_reference' => 'RECON001',
        'item_details' => ['financial_source' => 'master_agency_supply'],
        'price' => 300,
        'net_fare' => 300,
        'taxes' => [],
        'total_tax' => 50,
        'total' => 350,
        'total_amount' => 350,
        'currency' => 'LYD',
        'status' => 'issued',
        'commission_percent' => 10,
        'commission_amount' => 30,
        'net_after_commission' => 270,
        'agent_commission' => 30,
        'net_commission' => 30,
        'paid' => 350,
        'remaining' => 0,
    ]);

    // Create matching wallet withdrawal.
    $wallet = $this->admin->getOrCreateCurrencyWallet('LYD');
    $wallet->deposit(50000, ['type' => 'initial_fund']);
    $wallet->withdraw(32000, ['type' => 'ticket_purchase', 'order_id' => $order->id]);

    $this->artisan('finance:reconcile', ['tenantId' => tenant('id'), '--hours' => 1])
        ->assertSuccessful();
});

test('finance:reconcile reports discrepancy when wallet withdrawals differ from orders', function () {
    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $this->admin->id,
        'number' => 'ORD-DISC-001',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 500,
        'tax_total' => 50,
        'grand_total' => 550,
        'amount_paid' => 550,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'wallet',
        'contact' => ['email' => 'test@example.com'],
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'ticket',
        'product_subtype' => 'economy',
        'provider' => 'videcom',
        'provider_reference' => 'DISC001',
        'item_details' => ['financial_source' => 'master_agency_supply'],
        'price' => 500,
        'net_fare' => 500,
        'taxes' => [],
        'total_tax' => 50,
        'total' => 550,
        'total_amount' => 550,
        'currency' => 'LYD',
        'status' => 'issued',
        'commission_percent' => 5,
        'commission_amount' => 25,
        'net_after_commission' => 475,
        'agent_commission' => 25,
        'net_commission' => 25,
        'paid' => 550,
        'remaining' => 0,
    ]);

    // No wallet withdrawal — this creates a discrepancy.
    $this->artisan('finance:reconcile', ['tenantId' => tenant('id'), '--hours' => 1])
        ->assertFailed();
});

test('finance:reconcile respects hours option', function () {
    // Create an order from 48 hours ago.
    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $this->admin->id,
        'number' => 'ORD-OLD-001',
        'status' => 'issued',
        'issued_at' => now()->subHours(48),
        'subtotal' => 200,
        'tax_total' => 20,
        'grand_total' => 220,
        'amount_paid' => 220,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'wallet',
        'contact' => ['email' => 'test@example.com'],
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'ticket',
        'product_subtype' => 'economy',
        'provider' => 'videcom',
        'provider_reference' => 'OLD001',
        'item_details' => ['financial_source' => 'master_agency_supply'],
        'price' => 200,
        'net_fare' => 200,
        'taxes' => [],
        'total_tax' => 20,
        'total' => 220,
        'total_amount' => 220,
        'currency' => 'LYD',
        'status' => 'issued',
        'commission_percent' => 5,
        'commission_amount' => 10,
        'net_after_commission' => 190,
        'agent_commission' => 10,
        'net_commission' => 10,
        'paid' => 220,
        'remaining' => 0,
    ]);

    // With --hours=24, the old order should be excluded.
    $this->artisan('finance:reconcile', ['tenantId' => tenant('id'), '--hours' => 24])
        ->expectsOutput('  No financial activity in the last 24 hours.')
        ->assertSuccessful();
});
