<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantEsimProvider;
use App\Models\User;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'esim-flow-'.Str::random(4),
        'company_name' => 'ESim Flow Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $admin = User::factory()->create([
        'role' => 'admin',
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

    $state['tenant'] = $tenant;
    $state['admin'] = $admin;
    $state['baseUrl'] = 'http://'.$tenant->domains->first()->domain;
});

function fakeTenantL2EsimApi(): void
{
    Http::fake([
        'https://l2travelesim.com/api/whitelabel/v2/catalogue' => Http::response([
            'bundles' => [
                [
                    'name' => 'esim_1GB_30D_LY_U',
                    'description' => 'Libya 1GB 30 Days',
                    'countries' => [['name' => 'Libya', 'iso' => 'LY']],
                    'dataAmount' => 1024,
                    'duration' => 30,
                    'speed' => ['4G'],
                    'unlimited' => false,
                    'price' => 15.00,
                ],
                [
                    'name' => 'esim_3GB_30D_LY_U',
                    'description' => 'Libya 3GB 30 Days',
                    'countries' => [['name' => 'Libya', 'iso' => 'LY']],
                    'dataAmount' => 3072,
                    'duration' => 30,
                    'speed' => ['4G'],
                    'unlimited' => false,
                    'price' => 35.00,
                ],
            ],
        ], 200),
        'https://l2travelesim.com/api/whitelabel/v2/organization' => Http::response([
            'balance' => 500,
        ], 200),
        'https://l2travelesim.com/api/whitelabel/v2/processOrders' => Http::response([
            'orderReference' => 'L2-ORDER-001',
            'assigned' => true,
            'valid' => true,
            'order' => [[
                'esims' => [[
                    'iccid' => '8988247000100000001',
                    'matchingId' => 'MATCH-ID-001',
                    'smdpAddress' => 'smdp.example.com',
                ]],
            ]],
        ], 200),
    ]);
}

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('esim full flow searches, selects, and books a package creating order and deducting wallet', function () {
    global $state;

    fakeTenantL2EsimApi();

    $this->actingAs($state['admin']);

    // Fund the provider wallet (for provider-side deductions)
    $provider = TenantEsimProvider::query()->where('provider_type', 'l2')->firstOrFail();
    $wallet = $provider->getOrCreateCurrencyWallet('USD');
    $wallet->depositFloat(500, ['type' => 'test_provider_fund']);

    // Step 1: Search
    $searchResponse = $this->post($state['baseUrl'].route('esim.search', [], false), [
        'country' => 'LY',
    ]);

    $searchResponse->assertRedirect();
    $searchUuid = Str::afterLast((string) $searchResponse->headers->get('Location'), '/');

    // Step 2: Get packages
    $this->getJson($state['baseUrl'].route('esim.packages', ['uuid' => $searchUuid], false))
        ->assertSuccessful()
        ->assertJsonPath('packages.0.id', 'esim_1GB_30D_LY_U')
        ->assertJsonPath('packages.0.name', 'Libya 1GB 30 Days')
        ->assertJsonPath('packages.0.price', 15)
        ->assertJsonPath('packages.1.id', 'esim_3GB_30D_LY_U');

    // Step 3: Select a package
    $selectResponse = $this->post($state['baseUrl'].route('esim.select', ['uuid' => $searchUuid], false), [
        'package_id' => 'esim_1GB_30D_LY_U',
    ]);

    $selectResponse->assertRedirect();
    $bookingUuid = Str::afterLast((string) $selectResponse->headers->get('Location'), '/');

    // Step 4: Book
    $bookResponse = $this->post($state['baseUrl'].route('esim.book', [], false), [
        'booking_uuid' => $bookingUuid,
        'customer' => [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
        ],
    ]);

    $bookResponse->assertRedirect()->assertSessionMissing('error');

    $order = Order::query()->latest('created_at')->firstOrFail();
    expect((string) $bookResponse->headers->get('Location'))->toContain('/orders/'.$order->id);

    $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

    expect((string) $item->product_type)->toBe('esim')
        ->and(data_get($item->item_details, 'provider_order_id'))->toBe('L2-ORDER-001')
        ->and(data_get($item->item_details, 'iccid'))->toBe('8988247000100000001')
        ->and(data_get($item->item_details, 'activation_code'))->toBe('MATCH-ID-001')
        ->and(data_get($item->item_details, 'customer.name'))->toBe('Ahmed Ali')
        ->and(data_get($item->item_details, 'customer.email'))->toBe('ahmed@example.com')
        ->and(data_get($item->item_details, 'package_name'))->toBe('Libya 1GB 30 Days')
        ->and(data_get($item->item_details, 'country'))->toBe('LY')
        ->and((float) $item->net_fare)->toBe(15.00)
        ->and($item->wallet_transaction_id)->not->toBeNull();

    // In the direct/own-source flow, the deduction comes from the provider wallet
    $wallet->refresh();
    expect(round((float) $wallet->balanceFloat, 2))->toBe(485.0);

    expect(WalletTransaction::query()
        ->where('uuid', (string) $item->wallet_transaction_id)
        ->where('wallet_id', $wallet->id)
        ->exists())->toBeTrue();
});

test('esim booking fails early when provider wallet is insufficient', function () {
    global $state;

    Http::fake([
        'https://l2travelesim.com/api/whitelabel/v2/processOrders' => Http::response([
            'orderReference' => 'L2-ORDER-SHOULD-NOT-HAPPEN',
            'assigned' => true,
            'valid' => true,
            'order' => [[
                'esims' => [[
                    'iccid' => '000',
                    'matchingId' => 'NONE',
                    'smdpAddress' => 'smdp.example.com',
                ]],
            ]],
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $bookingUuid = (string) Str::uuid();
    Cache::put('esim_booking_'.$bookingUuid, [
        'search' => ['country' => 'LY'],
        'package' => [
            'id' => 'esim_1GB_30D_LY_U',
            'name' => 'Libya 1GB 30 Days',
            'country' => 'LY',
            'data_mb' => 1024,
            'validity_days' => 30,
            'price' => 15.00,
            'currency' => 'USD',
            'provider' => 'l2',
        ],
        'provider_source' => [],
        'created_at' => now()->toISOString(),
    ], now()->addMinutes(60));

    $response = $this->from($state['baseUrl'].route('esim.checkout', ['uuid' => $bookingUuid], false))
        ->post($state['baseUrl'].route('esim.book', [], false), [
            'booking_uuid' => $bookingUuid,
            'customer' => [
                'name' => 'Ahmed Ali',
                'email' => 'ahmed@example.com',
            ],
        ]);

    $response->assertRedirect();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/processOrders'));
    expect(Order::query()->count())->toBe(0);
});

test('esim booking fails when provider is not configured', function () {
    global $state;

    TenantEsimProvider::query()->delete();

    $this->actingAs($state['admin']);

    $bookingUuid = (string) Str::uuid();
    Cache::put('esim_booking_'.$bookingUuid, [
        'search' => ['country' => 'LY'],
        'package' => [
            'id' => 'esim_1GB_30D_LY_U',
            'name' => 'Libya 1GB 30 Days',
            'price' => 15.00,
            'currency' => 'USD',
            'provider' => 'l2',
        ],
        'provider_source' => [],
        'created_at' => now()->toISOString(),
    ], now()->addMinutes(60));

    $this->from($state['baseUrl'].route('esim.checkout', ['uuid' => $bookingUuid], false))
        ->post($state['baseUrl'].route('esim.book', [], false), [
            'booking_uuid' => $bookingUuid,
            'customer' => [
                'name' => 'Ahmed Ali',
                'email' => 'ahmed@example.com',
            ],
        ])
        ->assertSessionHas('error');

    expect(Order::query()->count())->toBe(0);
});

test('esim search redirects to results page with uuid', function () {
    global $state;

    $this->actingAs($state['admin']);

    $searchResponse = $this->post($state['baseUrl'].route('esim.search', [], false), [
        'country' => 'TN',
    ]);

    $searchResponse->assertRedirect();
    $location = (string) $searchResponse->headers->get('Location');
    expect($location)->toContain('/esim/results/');
});

test('esim results page renders with search uuid', function () {
    global $state;

    $this->actingAs($state['admin']);

    $searchUuid = (string) Str::uuid();
    Cache::put('esim_search_'.$searchUuid, [
        'country' => 'TN',
    ], now()->addMinutes(60));

    $this->get($state['baseUrl'].route('esim.results', ['uuid' => $searchUuid], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/ESim/Results')
            ->where('searchUuid', $searchUuid)
        );
});

test('esim checkout page renders with booking uuid and package details', function () {
    global $state;

    $this->actingAs($state['admin']);

    $bookingUuid = (string) Str::uuid();
    Cache::put('esim_booking_'.$bookingUuid, [
        'search' => ['country' => 'LY'],
        'package' => [
            'id' => 'esim_1GB_30D_LY_U',
            'name' => 'Libya 1GB 30 Days',
            'country' => 'LY',
            'data_mb' => 1024,
            'validity_days' => 30,
            'price' => 15.00,
            'currency' => 'USD',
            'provider' => 'l2',
        ],
        'provider_source' => [],
        'created_at' => now()->toISOString(),
    ], now()->addMinutes(60));

    $this->get($state['baseUrl'].route('esim.checkout', ['uuid' => $bookingUuid], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/ESim/Checkout')
            ->where('bookingUuid', $bookingUuid)
            ->where('package.id', 'esim_1GB_30D_LY_U')
            ->where('package.name', 'Libya 1GB 30 Days')
            ->where('package.price', 15)
        );
});

test('esim expired search redirects back to index', function () {
    global $state;

    $this->actingAs($state['admin']);

    $this->get($state['baseUrl'].route('esim.results', ['uuid' => (string) Str::uuid()], false))
        ->assertRedirect(route('esim.index'));
});

test('esim expired booking redirects back to index', function () {
    global $state;

    $this->actingAs($state['admin']);

    $this->get($state['baseUrl'].route('esim.checkout', ['uuid' => (string) Str::uuid()], false))
        ->assertRedirect(route('esim.index'));
});
