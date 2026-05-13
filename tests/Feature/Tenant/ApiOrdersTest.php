<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-order-'.Str::random(4),
        'company_name' => 'API Order Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $user = User::factory()->create([
        'email' => 'agent@example.com',
        'password' => 'Secret123!',
        'role' => 'agent',
        'is_active' => true,
    ]);

    // Create a test order
    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'ORD-TEST-001',
        'status' => 'confirmed',
        'issued_at' => now(),
        'subtotal' => 450,
        'tax_total' => 0,
        'grand_total' => 450,
        'amount_paid' => 450,
        'currency' => 'LYD',
        'payment_method' => 'airline_token',
        'payment_reference' => 'PNR001',
        'contact' => ['first_name' => 'John', 'last_name' => 'Doe'],
    ]);

    OrderItem::query()->create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'ticket',
        'product_subtype' => 'oneway',
        'provider' => 'videcom',
        'provider_reference' => 'PNR001',
        'item_details' => [
            'airline_code' => 'YI',
            'passengers' => [['first_name' => 'John', 'last_name' => 'Doe']],
        ],
        'status' => 'confirmed',
        'price' => 450,
        'total' => 450,
        'total_amount' => 450,
        'currency' => 'LYD',
    ]);

    $token = $user->createToken('Test Device')->plainTextToken;

    $state['tenant'] = $tenant;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
    $state['token'] = $token;
    $state['orderId'] = $order->id;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('orders index returns paginated list', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/orders');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.data.0.number', 'ORD-TEST-001')
        ->assertJsonPath('data.data.0.status', 'confirmed');
});

test('order show returns detail', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/orders/'.$state['orderId']);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.order.number', 'ORD-TEST-001')
        ->assertJsonPath('data.order.items.0.product_type', 'ticket');
});

test('dashboard returns stats', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/dashboard');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['current_month', 'growth', 'recent_bookings']]);
});

test('reports sales returns data', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/reports/sales');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['rows', 'grand_totals', 'filters']]);
});

test('reports commissions returns data', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/reports/commissions');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['items', 'filters']]);
});

test('wallet balance returns balances', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/wallet/balance');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonIsArray('data');
});

test('wallet transactions returns paginated list', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/wallet/transactions');

    $response->assertOk()
        ->assertJsonPath('success', true);
});

test('unauthenticated requests are rejected', function () {
    global $state;

    $this->getJson($state['apiUrl'].'/orders')->assertUnauthorized();
    $this->getJson($state['apiUrl'].'/dashboard')->assertUnauthorized();
    $this->getJson($state['apiUrl'].'/reports/sales')->assertUnauthorized();
    $this->getJson($state['apiUrl'].'/wallet/balance')->assertUnauthorized();
    $this->getJson($state['apiUrl'].'/wallet/transactions')->assertUnauthorized();
});
