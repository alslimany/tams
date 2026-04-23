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
        'id' => 'order-history-'.Str::random(4),
        'company_name' => 'Order History Agency',
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

    TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'Default',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    $state['order'] = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $state['user']->id,
        'number' => 'HIST0001AA',
        'status' => 'voided',
        'issued_at' => now(),
        'subtotal' => 200,
        'tax_total' => 0,
        'grand_total' => 200,
        'amount_paid' => 200,
        'amount_refunded' => 200,
        'currency' => 'LYD',
        'payment_method' => 'airline_token',
        'payment_reference' => 'AAHIST',
    ]);

    $historicalDetails = [
        'airline_code' => 'YI',
        'rloc' => 'AAHIST',
        'payments' => [
            ['reference' => 'HIST PAY REF'],
        ],
        'tickets' => [
            ['ticket_number' => '854 1000000001', 'status' => 'V'],
        ],
        'pnr_synced_at' => now()->subDay()->toIso8601String(),
    ];

    $state['voidedItem'] = OrderItem::query()->create([
        'order_id' => $state['order']->id,
        'type' => 'flight',
        'product_subtype' => 'oneway',
        'provider' => 'videcom',
        'provider_reference' => 'AAHIST',
        'ticket_number' => null,
        'item_details' => $historicalDetails,
        'price' => 100,
        'taxes' => 0,
        'total' => 100,
        'currency' => 'LYD',
        'status' => 'voided',
    ]);

    $state['refundedItem'] = OrderItem::query()->create([
        'order_id' => $state['order']->id,
        'type' => 'flight',
        'product_subtype' => 'oneway',
        'provider' => 'videcom',
        'provider_reference' => 'AAHIST',
        'ticket_number' => null,
        'item_details' => $historicalDetails,
        'price' => 100,
        'taxes' => 0,
        'total' => 100,
        'currency' => 'LYD',
        'status' => 'refunded',
    ]);

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldNotReceive('make');
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

test('order show keeps voided and refunded item snapshots untouched', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $voidedBefore = $state['voidedItem']->item_details;
    $refundedBefore = $state['refundedItem']->item_details;

    $this->get($baseUrl.route('orders.show', ['order' => $state['order']], false))
        ->assertOk();

    $voidedAfter = $state['voidedItem']->fresh();
    $refundedAfter = $state['refundedItem']->fresh();

    expect($voidedAfter->status)->toBe('voided')
        ->and($refundedAfter->status)->toBe('refunded')
        ->and($voidedAfter->item_details)->toBe($voidedBefore)
        ->and($refundedAfter->item_details)->toBe($refundedBefore);
});
