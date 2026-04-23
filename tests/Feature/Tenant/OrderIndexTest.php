<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'order-index-'.Str::random(4),
        'company_name' => 'Order Index Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    $state['tenant'] = $tenant;

    tenancy()->initialize($tenant);

    $state['user'] = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $provider = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'Default',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $state['user']->id,
        'number' => 'AAA0001BB',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 100,
        'tax_total' => 0,
        'grand_total' => 100,
        'amount_paid' => 100,
        'currency' => 'LYD',
        'payment_method' => 'airline_token',
        'payment_reference' => 'PNR123',
    ]);

    OrderItem::query()->create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_subtype' => 'oneway',
        'provider' => 'videcom',
        'provider_reference' => 'PNR123',
        'ticket_number' => '8543220420747',
        'item_details' => [
            'pnr' => 'PNR123',
            'airline_code' => $provider->airline_code,
            'segments' => [],
        ],
        'price' => 100,
        'taxes' => 0,
        'total' => 100,
        'currency' => 'LYD',
        'status' => 'issued',
    ]);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('manager can view orders index list', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->get($baseUrl.route('orders.index', [], false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Orders/Index')
            ->where('orders.data.0.number', 'AAA0001BB')
        );
});
