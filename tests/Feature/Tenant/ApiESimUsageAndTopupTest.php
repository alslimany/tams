<?php

use App\Models\Country;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantEsimProvider;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'esim-top-'.Str::random(4),
        'company_name' => 'eSIM Topup Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    tenancy()->initialize($tenant);

    $user = User::factory()->create([
        'email' => 'agent@example.com',
        'role' => 'agent',
        'is_active' => true,
    ]);

    TenantEsimProvider::query()->create([
        'provider_type' => 'l2',
        'name' => 'L2 Travel eSIM',
        'credentials' => [
            'base_url' => 'https://l2travelesim.com',
            'api_key' => 'test-api-key',
            'client_secret' => 'test-client-secret',
        ],
        'is_active' => true,
        'commission_esim' => 10,
        'currency' => 'USD',
    ]);

    Country::query()->updateOrCreate(
        ['alpha2' => 'tn'],
        [
            'alpha3' => 'TUN',
            'name_en' => 'Tunisia',
            'name_ar' => 'تونس',
            'name_fr' => 'Tunisie',
            'esim_featured' => true,
        ],
    );

    $state['tenant'] = $tenant;
    $state['user'] = $user;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
    $state['token'] = $user->createToken('Test Device', ['read', 'write', 'issue'])->plainTextToken;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

function seedIssuedEsimItem(): OrderItem
{
    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => User::query()->first()->id,
        'number' => 'ORD-ESIM-TOP-1',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 12.5,
        'tax_total' => 0,
        'grand_total' => 12.5,
        'amount_paid' => 12.5,
        'currency' => 'USD',
        'payment_method' => 'wallet',
        'payment_reference' => 'L2-ORIG',
        'contact' => ['full_name' => 'Mobile User', 'email' => 'mobile@example.com'],
    ]);

    return $order->items()->create([
        'type' => 'esim',
        'product_type' => 'esim',
        'product_subtype' => 'esim',
        'provider' => 'l2',
        'provider_reference' => 'L2-ORIG',
        'ticket_number' => '8988247000100000999',
        'item_details' => [
            'iccid' => '8988247000100000999',
            'country' => 'TN',
            'package_id' => 'esim_1GB_30D_TN_U',
            'customer' => ['name' => 'Mobile User', 'email' => 'mobile@example.com'],
            'usage' => [
                'remaining_mb' => 100,
                'percent_used' => 90,
                'initial_mb' => 1024,
                'unit' => 'BYTES',
            ],
        ],
        'product_details' => [
            'iccid' => '8988247000100000999',
            'country' => 'TN',
            'usage' => [
                'remaining_mb' => 100,
                'percent_used' => 90,
            ],
        ],
        'price' => 12.5,
        'net_fare' => 12.5,
        'taxes' => [],
        'total_tax' => 0,
        'total' => 12.5,
        'total_amount' => 12.5,
        'currency' => 'USD',
        'exchange_rate' => 1,
        'status' => 'issued',
        'transaction_type' => 'purchase',
        'commission_percent' => 0,
        'commission_amount' => 0,
        'net_after_commission' => 12.5,
        'agent_commission' => 0,
        'net_commission' => 0,
        'paid' => 12.5,
        'remaining' => 0,
    ]);
}

test('api returns esim usage from order item', function () {
    global $state;

    $item = seedIssuedEsimItem();

    test()->withToken($state['token'])
        ->getJson($state['apiUrl']."/orders/{$item->order_id}/esim-items/{$item->id}/usage")
        ->assertOk()
        ->assertJsonPath('data.has_usage_data', true)
        ->assertJsonPath('data.usage.remaining_mb', 100)
        ->assertJsonPath('data.iccid', '8988247000100000999');
});

test('api order show includes esim usage', function () {
    global $state;

    $item = seedIssuedEsimItem();

    test()->withToken($state['token'])
        ->getJson($state['apiUrl']."/orders/{$item->order_id}")
        ->assertOk()
        ->assertJsonPath('data.order.items.0.esim.usage.remaining_mb', 100);
});

test('api tops up existing esim with package id', function () {
    global $state;

    Http::fake([
        'https://l2travelesim.com/api/whitelabel/v2/catalogue' => Http::response([
            'bundles' => [[
                'name' => 'esim_1GB_30D_TN_U',
                'description' => 'Tunisia 1GB 30 Days',
                'countries' => [['name' => 'Tunisia', 'iso' => 'TN']],
                'dataAmount' => 1024,
                'duration' => 30,
                'speed' => ['4G'],
                'unlimited' => false,
                'price' => 12.50,
            ]],
        ], 200),
        'https://l2travelesim.com/api/whitelabel/v2/organization' => Http::response([
            'balance' => 500,
        ], 200),
        'https://l2travelesim.com/api/whitelabel/v2/processOrders' => Http::response([
            'orderReference' => 'L2-TOPUP-001',
            'assigned' => true,
            'valid' => true,
            'order' => [[
                'esims' => [[
                    'iccid' => '8988247000100000999',
                    'matchingId' => 'MATCH-TOPUP',
                    'smdpAddress' => 'smdp.example.com',
                ]],
            ]],
        ], 200),
    ]);

    $provider = TenantEsimProvider::query()->firstOrFail();
    $provider->getOrCreateCurrencyWallet('USD')->depositFloat(500, ['type' => 'test_fund']);

    $item = seedIssuedEsimItem();

    test()->withToken($state['token'])
        ->getJson($state['apiUrl']."/orders/{$item->order_id}/esim-items/{$item->id}/topup-packages")
        ->assertOk()
        ->assertJsonPath('data.packages.0.id', 'esim_1GB_30D_TN_U');

    test()->withToken($state['token'])
        ->postJson($state['apiUrl']."/orders/{$item->order_id}/esim-items/{$item->id}/topup", [
            'package_id' => 'esim_1GB_30D_TN_U',
        ])
        ->assertCreated()
        ->assertJsonPath('data.esim.transaction_type', 'topup')
        ->assertJsonPath('data.esim.iccid', '8988247000100000999')
        ->assertJsonPath('data.esim.parent_item_id', $item->id);

    expect(Order::query()->count())->toBe(2)
        ->and(OrderItem::query()->where('transaction_type', 'topup')->count())->toBe(1);

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'processOrders')) {
            return false;
        }

        return ($request['item'] ?? null) === 'esim_1GB_30D_TN_U'
            && ($request['iccid'] ?? null) === '8988247000100000999';
    });
});
