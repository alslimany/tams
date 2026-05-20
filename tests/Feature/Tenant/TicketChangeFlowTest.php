<?php

use App\Jobs\UpdateAirlineBalanceJob;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'ticket-change-'.Str::random(4),
        'company_name' => 'Ticket Change Agency',
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

    $state['provider'] = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'Default',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

/**
 * Seed an issued order item with segments stored in item_details.
 *
 * @param  array<int, array<string, mixed>>  $segments
 * @return array{Order, OrderItem}
 */
function seedIssuedOrderWithSegments(User $user, array $segments): array
{
    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'ORD-'.Str::upper(Str::random(8)),
        'status' => 'paid',
        'subtotal' => 500,
        'tax_total' => 0,
        'grand_total' => 500,
        'amount_paid' => 500,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'wallet',
        'payment_reference' => 'CHG123',
        'contact' => ['email' => 'john@example.com'],
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'type' => 'flight_ticket',
        'product_subtype' => 'economy',
        'provider' => 'videcom',
        'provider_reference' => 'CHG123',
        'ticket_number' => '6071234567890',
        'item_details' => [
            'airline_code' => 'YI',
            'rloc' => 'CHG123',
            'is_voidable' => false,
            'segments' => $segments,
            'tickets' => [
                ['ticket_number' => '6071234567890', 'issue_date' => now()->toDateString()],
            ],
        ],
        'price' => 500,
        'taxes' => 0,
        'total' => 500,
        'currency' => 'LYD',
        'status' => 'issued',
        'paid' => 500,
        'remaining' => 0,
    ]);

    return [$order, $item];
}

// ─── changeQuote endpoint ────────────────────────────────────────────────────

test('changeQuote returns outstanding amount and revalidation change type when same route and class', function () {
    global $state;

    $segments = [
        ['line' => 1, 'departure_airport' => 'MJI', 'arrival_airport' => 'TUN', 'class' => 'Y'],
    ];

    [$order, $item] = seedIssuedOrderWithSegments($state['user'], $segments);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('changeQuote')
        ->once()
        ->with('CHG123', 1, '0YL0800Y24MarMJITUNNN1')
        ->andReturn(['outstanding_amount' => 50, 'currency' => 'LYD']);

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get(
        $baseUrl.route('tickets.changeQuote', ['booking' => $order->id, 'ticket' => $item->id], false)
        .'?segment_line=1&new_segment_code=0YL0800Y24MarMJITUNNN1'
    );

    $response->assertSuccessful()
        ->assertJson([
            'outstanding_amount' => 50,
            'currency' => 'LYD',
            'change_type' => 'revalidation',
        ]);
});

test('changeQuote returns reissue change type when destination differs', function () {
    global $state;

    $segments = [
        ['line' => 1, 'departure_airport' => 'MJI', 'arrival_airport' => 'TUN', 'class' => 'Y'],
    ];

    [$order, $item] = seedIssuedOrderWithSegments($state['user'], $segments);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('changeQuote')
        ->once()
        ->with('CHG123', 1, '0YL0800Y24MarMJILHRNN1')
        ->andReturn(['outstanding_amount' => 200, 'currency' => 'LYD']);

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get(
        $baseUrl.route('tickets.changeQuote', ['booking' => $order->id, 'ticket' => $item->id], false)
        .'?segment_line=1&new_segment_code=0YL0800Y24MarMJILHRNN1'
    );

    $response->assertSuccessful()
        ->assertJson([
            'outstanding_amount' => 200,
            'currency' => 'LYD',
            'change_type' => 'reissue',
        ]);
});

test('changeQuote returns 422 when segment_line is zero', function () {
    global $state;

    [$order, $item] = seedIssuedOrderWithSegments($state['user'], []);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->get(
        $baseUrl.route('tickets.changeQuote', ['booking' => $order->id, 'ticket' => $item->id], false)
        .'?segment_line=0&new_segment_code=0YL0800Y24MarMJITUNNN1'
    )->assertStatus(422);
});

// ─── confirmChange endpoint ──────────────────────────────────────────────────

test('confirmChange revalidation path sets item status to changed and stores change details', function () {
    global $state;

    Queue::fake();

    $segments = [
        ['line' => 1, 'departure_airport' => 'MJI', 'arrival_airport' => 'TUN', 'class' => 'Y'],
    ];

    [$order, $item] = seedIssuedOrderWithSegments($state['user'], $segments);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('confirmChange')
        ->once()
        ->with('CHG123', 1, '0YL0800Y25MarMJITUNNN1', 'revalidation', 0.0)
        ->andReturn(['success' => true, 'raw_response' => 'OK REZT*R']);

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->from($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->post($baseUrl.route('tickets.confirmChange', ['booking' => $order->id, 'ticket' => $item->id], false), [
            'segment_line' => 1,
            'new_segment_code' => '0YL0800Y25MarMJITUNNN1',
        ])
        ->assertRedirect(route('tickets.changeConfirmation', ['booking' => $order->id, 'ticket' => $item->id]))
        ->assertSessionHas('success');

    $item->refresh();
    $order->refresh();

    expect($item->status)->toBe('changed')
        ->and(data_get($item->item_details, 'change.change_type'))->toBe('revalidation')
        ->and(data_get($item->item_details, 'change.segment_line'))->toBe(1)
        ->and(data_get($item->item_details, 'change.new_segment_code'))->toBe('0YL0800Y25MarMJITUNNN1')
        ->and(data_get($item->item_details, 'change.changed_at'))->not->toBeNull()
        ->and($order->status)->toBe('changed');

    Queue::assertPushed(UpdateAirlineBalanceJob::class);
});

test('confirmChange reissue path sets item status to changed with reissue change type', function () {
    global $state;

    Queue::fake();

    $segments = [
        ['line' => 1, 'departure_airport' => 'MJI', 'arrival_airport' => 'TUN', 'class' => 'Y'],
    ];

    [$order, $item] = seedIssuedOrderWithSegments($state['user'], $segments);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('confirmChange')
        ->once()
        ->with('CHG123', 1, '0YL0800Y25MarMJILHRNN1', 'reissue', 0.0)
        ->andReturn(['success' => true, 'raw_response' => 'OK EZV*R EZT*R']);

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->from($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->post($baseUrl.route('tickets.confirmChange', ['booking' => $order->id, 'ticket' => $item->id], false), [
            'segment_line' => 1,
            'new_segment_code' => '0YL0800Y25MarMJILHRNN1',
        ])
        ->assertRedirect(route('tickets.changeConfirmation', ['booking' => $order->id, 'ticket' => $item->id]))
        ->assertSessionHas('success');

    $item->refresh();
    $order->refresh();

    expect($item->status)->toBe('changed')
        ->and(data_get($item->item_details, 'change.change_type'))->toBe('reissue')
        ->and($order->status)->toBe('changed');

    Queue::assertPushed(UpdateAirlineBalanceJob::class);
});

test('confirmChange returns error when airline rejects the change', function () {
    global $state;

    $segments = [
        ['line' => 1, 'departure_airport' => 'MJI', 'arrival_airport' => 'TUN', 'class' => 'Y'],
    ];

    [$order, $item] = seedIssuedOrderWithSegments($state['user'], $segments);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('confirmChange')
        ->once()
        ->andReturn(['success' => false, 'raw_response' => 'ERROR: NOT AUTHORISED']);

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->from($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->post($baseUrl.route('tickets.confirmChange', ['booking' => $order->id, 'ticket' => $item->id], false), [
            'segment_line' => 1,
            'new_segment_code' => '0YL0800Y25MarMJITUNNN1',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    $item->refresh();

    expect($item->status)->toBe('issued');
});
