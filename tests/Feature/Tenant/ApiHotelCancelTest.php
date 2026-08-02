<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\TenantHotelProvider;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-hotel-cancel-'.Str::random(4),
        'company_name' => 'API Hotel Cancel Agency',
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

    $provider->getOrCreateCurrencyWallet('LYD')->depositFloat(1000, ['type' => 'test_provider_fund']);

    $state['tenant'] = $tenant;
    $state['user'] = $user;
    $state['provider'] = $provider;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
    $state['token'] = $user->createToken('Test Device', ['read', 'write', 'issue'])->plainTextToken;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

/**
 * @return array{0: Order, 1: \App\Models\Tenant\OrderItem}
 */
function seedIssuedHotelOrder(): array
{
    global $state;

    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $state['user']->id,
        'number' => 'ORD-HOTEL-CANCEL-1',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 275.55,
        'tax_total' => 0,
        'grand_total' => 275.55,
        'amount_paid' => 275.55,
        'currency' => 'LYD',
        'payment_method' => 'provider_wallet',
        'payment_reference' => '352',
        'contact' => [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ],
    ]);

    $item = $order->items()->create([
        'type' => 'hotel',
        'product_type' => 'hotel',
        'product_subtype' => 'hotel',
        'provider' => '3t',
        'provider_reference' => '352',
        'item_details' => [
            'booking_id' => '352',
            'booking_source' => 129,
            'provider_cost' => 250.50,
            'selling_price' => 275.55,
            'confirmed' => true,
        ],
        'product_details' => [
            'hotel' => ['hotel_id' => '45', 'name' => 'Hotel Paris'],
            'stay' => ['check_in' => '2026-09-01', 'check_out' => '2026-09-02'],
            'rooms' => [['name' => 'Double Room']],
        ],
        'price' => 275.55,
        'net_fare' => 250.50,
        'taxes' => [],
        'total_tax' => 0,
        'total' => 275.55,
        'total_amount' => 275.55,
        'currency' => 'LYD',
        'status' => 'issued',
    ]);

    return [$order, $item];
}

test('api cancels hotel booking and credits provider wallet', function () {
    global $state;

    Http::fake([
        'https://btob.3t.tn/hotels-api?method=cancel' => Http::response([
            'method' => 'cancel',
            'response' => [
                'bookingId' => '352',
                'canceled' => true,
                'cancellationFee' => 25.50,
            ],
            'error' => false,
        ], 200),
    ]);

    [$order, $item] = seedIssuedHotelOrder();
    $wallet = $state['provider']->getOrCreateCurrencyWallet('LYD');
    $balanceBefore = round((float) $wallet->balanceFloat, 2);

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl']."/orders/{$order->id}/hotel-items/{$item->id}/cancel");

    $response->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.outcome', 'cancelled')
        ->assertJsonPath('data.item_status', 'cancelled')
        ->assertJsonPath('data.order_status', 'cancelled')
        ->assertJsonPath('data.cancellation_fee', 25.5)
        ->assertJsonPath('data.refund_amount', 225);

    $item->refresh();
    $order->refresh();
    $wallet->refresh();

    expect((string) $item->status)->toBe('cancelled')
        ->and((string) $item->refund_status)->toBe('refunded')
        ->and((float) data_get($item->item_details, 'cancellation.refund_amount'))->toEqual(225)
        ->and((float) $order->amount_refunded)->toEqual(225)
        ->and(round((float) $wallet->balanceFloat, 2))->toBe(round($balanceBefore + 225.0, 2));

    $detail = $this->withToken($state['token'])
        ->getJson($state['apiUrl']."/orders/{$order->id}");

    $detail->assertSuccessful()
        ->assertJsonPath('data.order.items.0.hotel.can_cancel', false);

    expect((float) data_get($detail->json(), 'data.order.items.0.hotel.cancellation.refund_amount'))->toEqual(225);
});

test('api marks hotel cancellation pending when 3T denies auto cancel', function () {
    global $state;

    Http::fake([
        'https://btob.3t.tn/hotels-api?method=cancel' => Http::response([
            'error' => true,
            'errorCode' => '502',
            'errorMessage' => 'Cancellation Denied, booking closed please contact Administrator !!!  Cancellation Not Allowed Throught API a cancellation request have been sent !!!',
            'response' => [],
            'method' => 'cancel',
        ], 200),
    ]);

    [$order, $item] = seedIssuedHotelOrder();
    $wallet = $state['provider']->getOrCreateCurrencyWallet('LYD');
    $balanceBefore = round((float) $wallet->balanceFloat, 2);

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl']."/orders/{$order->id}/hotel-items/{$item->id}/cancel");

    $response->assertSuccessful()
        ->assertJsonPath('data.outcome', 'cancellation_requested')
        ->assertJsonPath('data.item_status', 'cancellation')
        ->assertJsonPath('data.order_status', 'cancellation')
        ->assertJsonPath('data.cancellation_request.status', 'requested');

    $wallet->refresh();
    expect(round((float) $wallet->balanceFloat, 2))->toBe($balanceBefore);
});

test('api rejects cancel for already cancelled hotel item', function () {
    global $state;

    [$order, $item] = seedIssuedHotelOrder();
    $item->update(['status' => 'cancelled']);

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl']."/orders/{$order->id}/hotel-items/{$item->id}/cancel");

    $response->assertStatus(422)
        ->assertJsonPath('message', 'This hotel booking cannot be cancelled in its current status.');
});
