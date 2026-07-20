<?php

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
        'id' => 'esim-fx-'.Str::random(4),
        'company_name' => 'ESim Exchange Rate Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $state['tenant'] = $tenant;
    $state['admin'] = $admin;
    $state['baseUrl'] = 'http://'.$tenant->domains->first()->domain;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('esim provider configuration stores usd to lyd exchange rate', function () {
    global $state;

    $this->actingAs($state['admin']);

    $this->post($state['baseUrl'].route('settings.esim.store', [], false), [
        'provider_type' => 'l2',
        'name' => 'L2 Travel eSIM',
        'base_url' => 'https://l2travelesim.com/api/v2',
        'api_key' => 'test-api-key',
        'client_secret' => 'test-client-secret',
        'is_active' => true,
        'commission_esim' => 5,
        'usd_to_lyd_rate' => 4.85,
    ])->assertRedirect();

    $provider = TenantEsimProvider::query()->where('provider_type', 'l2')->firstOrFail();

    expect($provider->usdToLydRate())->toBe(4.85)
        ->and($provider->convertsUsdToLyd())->toBeTrue();

    $this->get($state['baseUrl'].route('settings.esim.index', [], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Settings/ESim')
            ->where('providers.0.usd_to_lyd_rate', 4.85)
        );
});

test('esim catalogue prices convert to lyd when exchange rate is configured', function () {
    global $state;

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
        'usd_to_lyd_rate' => 5,
    ]);

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
            ],
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $searchResponse = $this->post($state['baseUrl'].route('esim.search', [], false), [
        'country' => 'LY',
    ]);

    $searchResponse->assertRedirect();
    $searchUuid = Str::afterLast((string) $searchResponse->headers->get('Location'), '/');

    $this->getJson($state['baseUrl'].route('esim.packages', ['uuid' => $searchUuid], false))
        ->assertSuccessful()
        ->assertJsonPath('packages.0.price', 75)
        ->assertJsonPath('packages.0.currency', 'LYD')
        ->assertJsonPath('packages.0.provider_price', 15)
        ->assertJsonPath('packages.0.provider_currency', 'USD')
        ->assertJsonPath('packages.0.exchange_rate', 5);
});

test('esim booking with exchange rate stores lyd selling price and withdraws usd provider cost', function () {
    global $state;

    $provider = TenantEsimProvider::query()->create([
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
        'usd_to_lyd_rate' => 5,
    ]);

    $wallet = $provider->getOrCreateCurrencyWallet('USD');
    $wallet->depositFloat(500, ['type' => 'test_provider_fund']);

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
            ],
        ], 200),
        'https://l2travelesim.com/api/whitelabel/v2/organization' => Http::response([
            'balance' => 500,
        ], 200),
        'https://l2travelesim.com/api/whitelabel/v2/processOrders' => Http::response([
            'orderReference' => 'L2-ORDER-FX-001',
            'assigned' => true,
            'valid' => true,
            'order' => [[
                'esims' => [[
                    'iccid' => '8988247000100000099',
                    'matchingId' => 'MATCH-FX-001',
                    'smdpAddress' => 'smdp.example.com',
                ]],
            ]],
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $searchResponse = $this->post($state['baseUrl'].route('esim.search', [], false), [
        'country' => 'LY',
    ]);
    $searchUuid = Str::afterLast((string) $searchResponse->headers->get('Location'), '/');

    $selectResponse = $this->post($state['baseUrl'].route('esim.select', ['uuid' => $searchUuid], false), [
        'package_id' => 'esim_1GB_30D_LY_U',
    ]);
    $bookingUuid = Str::afterLast((string) $selectResponse->headers->get('Location'), '/');

    $this->post($state['baseUrl'].route('esim.book', [], false), [
        'booking_uuid' => $bookingUuid,
        'customer' => [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
        ],
    ])->assertRedirect()->assertSessionMissing('error');

    $order = Order::query()->latest('created_at')->firstOrFail();
    $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

    expect((string) $order->currency)->toBe('LYD')
        ->and((float) $order->grand_total)->toBe(75.0)
        ->and((float) $item->total)->toBe(75.0)
        ->and((string) $item->currency)->toBe('LYD')
        ->and((float) $item->exchange_rate)->toBe(5.0)
        ->and((float) data_get($item->item_details, 'provider_cost'))->toBe(15.0)
        ->and((string) data_get($item->item_details, 'provider_currency'))->toBe('USD');

    $wallet->refresh();
    expect(round((float) $wallet->balanceFloat, 2))->toBe(485.0);
});

test('esim prices remain in usd when exchange rate is empty', function () {
    global $state;

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
        'usd_to_lyd_rate' => null,
    ]);

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
            ],
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $searchResponse = $this->post($state['baseUrl'].route('esim.search', [], false), [
        'country' => 'LY',
    ]);
    $searchUuid = Str::afterLast((string) $searchResponse->headers->get('Location'), '/');

    $this->getJson($state['baseUrl'].route('esim.packages', ['uuid' => $searchUuid], false))
        ->assertSuccessful()
        ->assertJsonPath('packages.0.price', 15)
        ->assertJsonPath('packages.0.currency', 'USD')
        ->assertJsonPath('packages.0.provider_price', 15)
        ->assertJsonPath('packages.0.provider_currency', 'USD');
});
