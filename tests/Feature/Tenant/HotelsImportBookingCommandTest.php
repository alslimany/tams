<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantHotelProvider;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'hotel-import-'.Str::random(4),
        'company_name' => 'Hotel Import Agency',
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

    $provider = TenantHotelProvider::query()->create([
        'provider_type' => '3t',
        'name' => '3T Hotels',
        'credentials' => [
            'base_url' => 'https://btob.3t.tn',
            'api_key' => 'test-api-key',
            'login' => 'test-login',
            'password' => 'test-password',
        ],
        'is_active' => true,
        'commission_hotel' => 10,
        'currency' => 'LYD',
    ]);

    $wallet = $provider->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(1000, ['type' => 'test_provider_fund']);

    $payloadPath = storage_path('framework/testing/hotel-import-352.json');
    File::ensureDirectoryExists(dirname($payloadPath));
    File::put($payloadPath, json_encode([
        'method' => 'book',
        'response' => [
            'bookingId' => '352',
            'confirmed' => true,
            'booking' => [
                'hotel' => [
                    'hotelId' => 45,
                    'hotelUid' => 'hotel_45',
                    'hotelName' => 'Hotel Paris',
                    'cityId' => 215,
                    'cityName' => 'Paris',
                    'countryName' => 'France',
                    'supplierSourceId' => '129',
                ],
                'from' => '2026-09-01',
                'to' => '2026-09-02',
                'rooms' => [[
                    'roomIndex' => 1,
                    'rateKey' => 'RATE-123',
                    'name' => 'Double Room',
                    'boardName' => 'BED AND BREAKFAST',
                    'price' => 250.50,
                    'currency' => 'LYD',
                    'paxes' => [
                        'adult' => '2',
                        'child' => ['value' => '0', 'age' => ''],
                    ],
                ]],
            ],
            'returnedPrice' => true,
            'totalPurchase' => 250.50,
            'currency' => 'LYD',
            'comments' => 'Tourist tax payable at hotel.',
        ],
        'customer' => [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'agent@example.com',
            'mobile' => '+218911234567',
            'country' => 'Libya',
            'city' => 'Tripoli',
        ],
    ], JSON_PRETTY_PRINT));

    $state['tenant'] = $tenant;
    $state['user'] = $user;
    $state['provider'] = $provider;
    $state['payloadPath'] = $payloadPath;
});

afterEach(function () {
    global $state;

    if (isset($state['payloadPath']) && File::exists($state['payloadPath'])) {
        File::delete($state['payloadPath']);
    }

    tenancy()->end();
    $state['tenant']->delete();
});

test('hotels:import-booking creates order and debits wallet', function () {
    global $state;

    $wallet = $state['provider']->getOrCreateCurrencyWallet('LYD');
    $balanceBefore = round((float) $wallet->balanceFloat, 2);

    $this->artisan('hotels:import-booking', [
        'tenant' => $state['tenant']->id,
        '--booking-id' => '352',
        '--user-id' => (string) $state['user']->id,
        '--payload' => $state['payloadPath'],
        '--debit-wallet' => true,
    ])->assertSuccessful();

    $order = Order::query()->where('payment_reference', '352')->first();

    expect($order)->not->toBeNull()
        ->and($order->status)->toBe('issued')
        ->and((float) $order->grand_total)->toBe(275.55);

    $item = OrderItem::query()->where('order_id', $order->id)->first();

    expect($item)->not->toBeNull()
        ->and((string) $item->provider_reference)->toBe('352')
        ->and((string) $item->product_type)->toBe('hotel')
        ->and((float) $item->net_fare)->toBe(250.50);

    $wallet->refresh();
    expect(round((float) $wallet->balanceFloat, 2))->toBe(round($balanceBefore - 250.50, 2));
});

test('hotels:import-booking is idempotent for the same booking id', function () {
    global $state;

    $this->artisan('hotels:import-booking', [
        'tenant' => $state['tenant']->id,
        '--booking-id' => '352',
        '--user-id' => (string) $state['user']->id,
        '--payload' => $state['payloadPath'],
        '--debit-wallet' => true,
    ])->assertSuccessful();

    $wallet = $state['provider']->getOrCreateCurrencyWallet('LYD');
    $balanceAfterFirst = round((float) $wallet->balanceFloat, 2);

    $this->artisan('hotels:import-booking', [
        'tenant' => $state['tenant']->id,
        '--booking-id' => '352',
        '--user-id' => (string) $state['user']->id,
        '--payload' => $state['payloadPath'],
        '--debit-wallet' => true,
    ])->assertSuccessful();

    expect(Order::query()->where('payment_reference', '352')->count())->toBe(1)
        ->and(OrderItem::query()->where('provider_reference', '352')->count())->toBe(1);

    $wallet->refresh();
    expect(round((float) $wallet->balanceFloat, 2))->toBe($balanceAfterFirst);
});

test('hotels:import-booking dry-run does not create an order', function () {
    global $state;

    $this->artisan('hotels:import-booking', [
        'tenant' => $state['tenant']->id,
        '--booking-id' => '352',
        '--user-id' => (string) $state['user']->id,
        '--payload' => $state['payloadPath'],
        '--debit-wallet' => true,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(Order::query()->where('payment_reference', '352')->exists())->toBeFalse();
});
