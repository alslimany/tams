<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function (): void {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-hotel-pdf-'.Str::random(4),
        'company_name' => 'API Hotel PDF Agency',
        'owner_email' => 'ops@api-hotel-pdf.test',
        'owner_phone' => '+218912345000',
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

    $state['tenant'] = $tenant;
    $state['user'] = $user;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
    $state['token'] = $user->createToken('Test Device', ['read'])->plainTextToken;
});

afterEach(function (): void {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

function makeHotelOrder(User $user): Order
{
    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'ORD-HTL-'.Str::upper(Str::random(6)),
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 640,
        'tax_total' => 0,
        'grand_total' => 700,
        'amount_paid' => 700,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'wallet',
        'payment_reference' => 'WLT-11223344',
        'contact' => [
            'first_name' => 'Sami',
            'last_name' => 'Ali',
            'email' => 'sami@example.com',
            'phone' => '+218912000111',
        ],
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'type' => 'hotel',
        'product_type' => 'hotel',
        'product_subtype' => 'hotel',
        'provider' => '3T Hotels',
        'provider_reference' => 'BID-99887',
        'ticket_number' => 'REF-3T-77',
        'status' => 'confirmed',
        'price' => 640,
        'net_fare' => 640,
        'taxes' => 0,
        'total' => 700,
        'total_amount' => 700,
        'commission_amount' => 60,
        'currency' => 'LYD',
        'paid' => 700,
        'remaining' => 0,
        'item_details' => [
            'booking_id' => 'BID-99887',
            'booking_ref' => 'REF-3T-77',
            'confirmed' => true,
            'total_purchase' => 640,
            'markup_amount' => 60,
            'markup_percent' => 10,
            'provider_currency' => 'LYD',
            'comments' => 'Late check-in confirmed with property.',
            'provider_booking' => [
                'hotel' => [
                    'hotel_name' => 'Grand Bay Hotel',
                    'city_name' => 'Paris',
                    'country_name' => 'France',
                    'rating' => 4,
                ],
                'from' => '2026-09-10',
                'to' => '2026-09-13',
                'deadline' => '2026-09-05',
                'rooms' => [
                    [
                        'roomIndex' => 1,
                        'name' => 'Deluxe Double',
                        'board_name' => 'Half board',
                        'paxes' => [
                            ['civility' => 'Mr', 'firstName' => 'Sami', 'lastName' => 'Ali', 'type' => 'AD'],
                            ['civility' => 'Mrs', 'firstName' => 'Layla', 'lastName' => 'Ali', 'type' => 'AD'],
                        ],
                    ],
                ],
            ],
            'selected_offer' => [
                'room_name' => 'Deluxe Double',
                'board_name' => 'Half board',
                'cancellation_policies' => [
                    ['dateFrom' => '2026-09-06', 'amount' => 200, 'type' => 'fixed'],
                    ['dateFrom' => '2026-09-09', 'amount' => 640, 'type' => 'full'],
                ],
            ],
            'customer' => [
                'name' => 'Sami Ali',
                'email' => 'sami@example.com',
                'phone' => '+218912000111',
            ],
        ],
        'product_details' => [
            'provider' => '3T Hotels',
            'product_subtype' => 'hotel',
            'hotel' => [
                'hotel_name' => 'Grand Bay Hotel',
                'name' => 'Grand Bay Hotel',
                'city_name' => 'Paris',
                'country_name' => 'France',
                'rating' => 4,
            ],
            'stay' => [
                'from' => '2026-09-10',
                'to' => '2026-09-13',
                'deadline' => '2026-09-05',
            ],
            'rooms' => [
                [
                    'roomIndex' => 1,
                    'name' => 'Deluxe Double',
                    'board_name' => 'Half board',
                    'paxes' => [
                        ['civility' => 'Mr', 'firstName' => 'Sami', 'lastName' => 'Ali', 'type' => 'AD'],
                        ['civility' => 'Mrs', 'firstName' => 'Layla', 'lastName' => 'Ali', 'type' => 'AD'],
                    ],
                ],
            ],
            'customer' => [
                'name' => 'Sami Ali',
                'email' => 'sami@example.com',
                'phone' => '+218912000111',
            ],
        ],
    ]);

    return $order->fresh(['items']);
}

test('api hotel voucher pdf returns a pdf for a hotel order item', function () {
    global $state;

    $order = makeHotelOrder($state['user']);
    $item = $order->items->firstOrFail();

    $this->withToken($state['token'])
        ->get($state['apiUrl'].'/orders/'.$order->id.'/hotel-items/'.$item->id.'/voucher-pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('api hotel voucher pdf returns 404 when item does not belong to order', function () {
    global $state;

    $orderA = makeHotelOrder($state['user']);
    $orderB = makeHotelOrder($state['user']);
    $foreignItem = $orderB->items->firstOrFail();

    $this->withToken($state['token'])
        ->get($state['apiUrl'].'/orders/'.$orderA->id.'/hotel-items/'.$foreignItem->id.'/voucher-pdf')
        ->assertStatus(404);
});

test('api hotel voucher pdf returns 404 for a non hotel item', function () {
    global $state;

    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $state['user']->id,
        'number' => 'ORD-FLT-'.Str::upper(Str::random(6)),
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 100,
        'tax_total' => 0,
        'grand_total' => 100,
        'amount_paid' => 100,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'wallet',
        'contact' => [],
    ]);

    $flightItem = OrderItem::create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'flight',
        'provider' => 'test',
        'provider_reference' => 'PNR-XYZ',
        'ticket_number' => 'TKT-XYZ',
        'status' => 'issued',
        'price' => 100,
        'total' => 100,
        'total_amount' => 100,
        'currency' => 'LYD',
        'item_details' => [],
        'product_details' => [],
    ]);

    $this->withToken($state['token'])
        ->get($state['apiUrl'].'/orders/'.$order->id.'/hotel-items/'.$flightItem->id.'/voucher-pdf')
        ->assertStatus(404);
});

test('api hotel voucher pdf rejects tokens without read ability', function () {
    global $state;

    $order = makeHotelOrder($state['user']);
    $item = $order->items->firstOrFail();
    $writeOnlyToken = $state['user']->createToken('Write Only', ['write'])->plainTextToken;

    $this->withToken($writeOnlyToken)
        ->get($state['apiUrl'].'/orders/'.$order->id.'/hotel-items/'.$item->id.'/voucher-pdf')
        ->assertStatus(403);
});
