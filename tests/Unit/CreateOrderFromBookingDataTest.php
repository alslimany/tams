<?php

use App\Actions\Finance\CreateOrderFromBookingData;
use App\DTOs\Videcom\OrderItemData;
use App\DTOs\Videcom\ParsedBookingData;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Orders\OrderNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, RefreshDatabase::class);

/** @var array{tenant?: Tenant} $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'finance-order-'.Str::random(4),
        'company_name' => 'Finance Order Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    $state['tenant'] = $tenant;

    tenancy()->initialize($tenant);
});

afterEach(function () {
    global $state;

    tenancy()->end();

    if (isset($state['tenant'])) {
        $state['tenant']->delete();
    }
});

test('it creates an issued order and segment level order items from parsed booking data', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->actingAs($user);

    $bookingData = new ParsedBookingData(
        pnr: 'PNR123',
        grandTotal: 330,
        currency: 'USD',
        paymentMethod: 'invoice',
        paymentReference: 'PAY-123',
        items: [
            new OrderItemData(
                passengerName: 'John Doe',
                segments: [
                    [
                        'flight_number' => 'YI101',
                        'departure_airport' => 'MJI',
                        'arrival_airport' => 'IST',
                    ],
                    [
                        'flight_number' => 'YI102',
                        'departure_airport' => 'IST',
                        'arrival_airport' => 'MJI',
                    ],
                ],
                fare: 200,
                taxes: 40,
                total: 240,
                ticketNumber: '6070000000001',
                commission: 20,
                airlineCode: 'YI',
                currency: 'USD',
            ),
            new OrderItemData(
                passengerName: 'Jane Doe',
                segments: [
                    [
                        'flight_number' => 'YI201',
                        'departure_airport' => 'MJI',
                        'arrival_airport' => 'TUN',
                    ],
                ],
                fare: 75,
                taxes: 15,
                total: 90,
                ticketNumber: '6070000000002',
                commission: 5,
                airlineCode: 'YI',
                currency: 'USD',
            ),
        ],
    );

    $order = app(CreateOrderFromBookingData::class)->execute($bookingData);

    expect($order->owner_type)->toBe(User::class)
        ->and($order->owner_id)->toBe($user->id)
        ->and($order->number)->toMatch('/^[A-Z]{3}\d{4}[A-Z]{2}$/')
        ->and($order->status)->toBe('issued')
        ->and($order->payment_method)->toBe('invoice')
        ->and($order->payment_reference)->toBe('PAY-123')
        ->and((float) $order->subtotal)->toBe(275.0)
        ->and((float) $order->tax_total)->toBe(55.0)
        ->and((float) $order->grand_total)->toBe(330.0)
        ->and((float) $order->amount_paid)->toBe(330.0)
        ->and($order->items)->toHaveCount(3);

    $segmentItem = $order->items
        ->where('ticket_number', '6070000000001')
        ->first();

    expect($segmentItem)->not->toBeNull()
        ->and($segmentItem->product_type)->toBe('ticket')
        ->and($segmentItem->transaction_type)->toBe('issue')
        ->and((float) $segmentItem->remaining)->toBe(0.0);
});

test('it rolls back the transaction when creating a segment item fails', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->actingAs($user);

    $bookingData = new ParsedBookingData(
        pnr: 'ROLLBK1',
        grandTotal: 100,
        currency: 'USD',
        paymentMethod: 'cash',
        paymentReference: null,
        items: [
            new OrderItemData(
                passengerName: 'Rollback Passenger',
                segments: [
                    [
                        'flight_number' => 'YI900',
                        'departure_airport' => 'MJI',
                        'arrival_airport' => 'IST',
                    ],
                ],
                fare: 80,
                taxes: 20,
                total: 100,
                ticketNumber: null,
                commission: 0,
                airlineCode: 'YI',
                currency: 'TOOLONG',
            ),
        ],
    );

    $action = new class(app(OrderNumberGenerator::class)) extends CreateOrderFromBookingData
    {
        protected function buildProductDetails(OrderItemData $item, array $segment): array
        {
            throw new RuntimeException('Forced failure while building product details.');
        }
    };

    expect(fn () => $action->execute($bookingData))
        ->toThrow(RuntimeException::class, 'Forced failure while building product details.');

    expect(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0);
});

test('it leaves commission fields untouched during initial order creation', function () {
    $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));

    DB::connection($connection)->table('airport_countries')->insert([
        ['country_code' => 'LY', 'country_name' => 'Libya', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['country_code' => 'TR', 'country_name' => 'Turkey', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    TenantProvider::query()->create([
        'airline_code' => 'YI',
        'airline_name' => 'Yemenia',
        'account_name' => 'Default',
        'provider_type' => 'videcom',
        'is_active' => true,
        'commission_domestic' => 5.0,
        'commission_international' => 10.0,
        'credentials' => [],
    ]);

    /** @var User $user */
    $user = User::factory()->create(['role' => 'manager', 'is_active' => true]);

    $this->actingAs($user);

    $bookingData = new ParsedBookingData(
        pnr: 'COMM001',
        grandTotal: 110,
        currency: 'USD',
        paymentMethod: 'invoice',
        paymentReference: null,
        items: [
            new OrderItemData(
                passengerName: 'Commission Passenger',
                segments: [
                    [
                        'flight_number' => 'YI301',
                        'departure_airport' => 'LY',
                        'arrival_airport' => 'TR',
                    ],
                ],
                fare: 100,
                taxes: 10,
                total: 110,
                ticketNumber: '6070000000010',
                commission: 0,
                airlineCode: 'YI',
                currency: 'USD',
            ),
        ],
    );

    $order = app(CreateOrderFromBookingData::class)->execute($bookingData);

    $item = $order->items->first();

    expect((float) $item->commission_percent)->toBe(0.0)
        ->and((float) $item->commission_amount)->toBe(0.0)
        ->and((float) $item->net_after_commission)->toBe(100.0);
});

test('it does not create airline account transactions during initial order creation', function () {
    TenantProvider::query()->create([
        'airline_code' => 'YI',
        'airline_name' => 'Yemenia',
        'account_name' => 'Default',
        'provider_type' => 'videcom',
        'is_active' => true,
        'commission_domestic' => 0.0,
        'commission_international' => 0.0,
        'credentials' => [],
    ]);

    /** @var User $user */
    $user = User::factory()->create(['role' => 'manager', 'is_active' => true]);

    $this->actingAs($user);

    $bookingData = new ParsedBookingData(
        pnr: 'AIRL001',
        grandTotal: 100,
        currency: 'USD',
        paymentMethod: 'invoice',
        paymentReference: null,
        items: [
            new OrderItemData(
                passengerName: 'Airline Passenger',
                segments: [
                    [
                        'flight_number' => 'YI401',
                        'departure_airport' => 'MJI',
                        'arrival_airport' => 'IST',
                    ],
                ],
                fare: 80,
                taxes: 20,
                total: 100,
                ticketNumber: '6070000000020',
                commission: 0,
                airlineCode: 'YI',
                currency: 'USD',
            ),
        ],
    );

    $order = app(CreateOrderFromBookingData::class)->execute($bookingData);

    expect(\App\Models\Tenant\AirlineTransaction::query()->count())->toBe(0)
        ->and(\App\Models\Tenant\AirlineAccount::query()->count())->toBe(0);

    $item = $order->items->first();

    expect($item->airline_transaction_id)->toBeNull();
});

test('it does not create airline account transaction when no provider is configured', function () {
    /** @var User $user */
    $user = User::factory()->create(['role' => 'manager', 'is_active' => true]);

    $this->actingAs($user);

    $bookingData = new ParsedBookingData(
        pnr: 'NOPROV1',
        grandTotal: 100,
        currency: 'USD',
        paymentMethod: 'invoice',
        paymentReference: null,
        items: [
            new OrderItemData(
                passengerName: 'No Provider Passenger',
                segments: [
                    [
                        'flight_number' => 'YI501',
                        'departure_airport' => 'MJI',
                        'arrival_airport' => 'IST',
                    ],
                ],
                fare: 80,
                taxes: 20,
                total: 100,
                ticketNumber: '6070000000030',
                commission: 0,
                airlineCode: 'YI',
                currency: 'USD',
            ),
        ],
    );

    app(CreateOrderFromBookingData::class)->execute($bookingData);

    expect(\App\Models\Tenant\AirlineTransaction::query()->count())->toBe(0)
        ->and(\App\Models\Tenant\AirlineAccount::query()->count())->toBe(0);
});
