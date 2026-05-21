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
            'base_url' => 'https://l2travelesim.com/api/v2',
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

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('esim full flow searches, selects, and books a package creating order and deducting wallet', function () {
    global $state;

    Http::fake([
        'https://l2travelesim.com/api/v2/packages*' => Http::response([
            [
                'id' => 'PKG-LY-1GB',
                'name' => 'Libya 1GB 30 Days',
                'country' => 'Libya',
                'data_mb' => 1024,
                'validity' => 30,
                'price' => 15.00,
                'currency' => 'USD',
            ],
            [
                'id' => 'PKG-LY-3GB',
                'name' => 'Libya 3GB 30 Days',
                'country' => 'Libya',
                'data_mb' => 3072,
                'validity' => 30,
                'price' => 35.00,
                'currency' => 'USD',
            ],
        ], 200),
        'https://l2travelesim.com/api/v2/orders' => Http::response([
            'order_id' => 'L2-ORDER-001',
            'iccid' => '8988247000100000001',
            'activation_code' => 'LPA:1$smdp.example.com$MATCH-ID-001',
            'qr_code_url' => 'https://qr.example.com/esim-001.png',
            'status' => 'active',
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    // Fund the provider wallet (for provider-side deductions)
    $provider = TenantEsimProvider::query()->where('provider_type', 'l2')->firstOrFail();
    $wallet = $provider->getOrCreateCurrencyWallet('USD');
    $wallet->depositFloat(500, ['type' => 'test_provider_fund']);

    // Fund the admin user's wallet (ProcessWalletTransactions resolves the first admin/manager)
    $adminWallet = $state['admin']->getOrCreateCurrencyWallet('USD');
    $adminWallet->depositFloat(500, ['type' => 'test_user_fund']);

    // Step 1: Search
    $searchResponse = $this->post($state['baseUrl'].route('esim.search', [], false), [
        'country' => 'Libya',
        'data_mb' => 1024,
        'validity_days' => 30,
    ]);

    $searchResponse->assertRedirect();
    $searchUuid = Str::afterLast((string) $searchResponse->headers->get('Location'), '/');

    // Step 2: Get packages
    $packagesResponse = $this->getJson($state['baseUrl'].route('esim.packages', ['uuid' => $searchUuid], false))
        ->assertSuccessful()
        ->assertJsonPath('packages.0.id', 'PKG-LY-1GB')
        ->assertJsonPath('packages.0.name', 'Libya 1GB 30 Days')
        ->assertJsonPath('packages.0.price', 15)
        ->assertJsonPath('packages.1.id', 'PKG-LY-3GB');

    // Step 3: Select a package
    $selectResponse = $this->post($state['baseUrl'].route('esim.select', ['uuid' => $searchUuid], false), [
        'package_id' => 'PKG-LY-1GB',
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
        ->and(data_get($item->item_details, 'activation_code'))->toBe('LPA:1$smdp.example.com$MATCH-ID-001')
        ->and(data_get($item->item_details, 'qr_code_url'))->toBe('https://qr.example.com/esim-001.png')
        ->and(data_get($item->item_details, 'customer.name'))->toBe('Ahmed Ali')
        ->and(data_get($item->item_details, 'customer.email'))->toBe('ahmed@example.com')
        ->and(data_get($item->item_details, 'package_name'))->toBe('Libya 1GB 30 Days')
        ->and(data_get($item->item_details, 'country'))->toBe('Libya')
        ->and((float) $item->net_fare)->toBe(15.00)
        ->and($item->wallet_transaction_id)->not->toBeNull();

    // In the direct/own-source flow, the deduction comes from the admin user's wallet
    $adminWallet->refresh();
    expect(round((float) $adminWallet->balanceFloat, 2))->toBe(485.0);

    expect(WalletTransaction::query()
        ->where('uuid', (string) $item->wallet_transaction_id)
        ->where('wallet_id', $adminWallet->id)
        ->exists())->toBeTrue();
});

test('esim booking fails early when provider wallet is insufficient', function () {
    global $state;

    Http::fake([
        'https://l2travelesim.com/api/v2/orders' => Http::response([
            'order_id' => 'L2-ORDER-SHOULD-NOT-HAPPEN',
            'iccid' => '000',
            'activation_code' => 'LPA:1$smdp.example.com$NONE',
            'status' => 'active',
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $bookingUuid = (string) Str::uuid();
    Cache::put('esim_booking_'.$bookingUuid, [
        'search' => ['country' => 'Libya', 'data_mb' => 1024, 'validity_days' => 30],
        'package' => [
            'id' => 'PKG-LY-1GB',
            'name' => 'Libya 1GB 30 Days',
            'country' => 'Libya',
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

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/orders'));
    expect(Order::query()->count())->toBe(0);
});

test('esim booking fails when provider is not configured', function () {
    global $state;

    TenantEsimProvider::query()->delete();

    $this->actingAs($state['admin']);

    $bookingUuid = (string) Str::uuid();
    Cache::put('esim_booking_'.$bookingUuid, [
        'search' => ['country' => 'Libya'],
        'package' => [
            'id' => 'PKG-LY-1GB',
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
        'country' => 'Tunisia',
        'data_mb' => 512,
        'validity_days' => 7,
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
        'country' => 'Tunisia',
        'data_mb' => 512,
        'validity_days' => 7,
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
        'search' => ['country' => 'Libya', 'data_mb' => 1024, 'validity_days' => 30],
        'package' => [
            'id' => 'PKG-LY-1GB',
            'name' => 'Libya 1GB 30 Days',
            'country' => 'Libya',
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
            ->where('package.id', 'PKG-LY-1GB')
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
