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
        'id' => 'ticket-void-'.Str::random(4),
        'company_name' => 'Ticket Void Agency',
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

function seedIssuedOrder(User $user, string $issueDate): array
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
        'payment_reference' => 'ABC123',
        'contact' => ['email' => 'john@example.com'],
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'type' => 'flight_ticket',
        'product_subtype' => 'economy',
        'provider' => 'videcom',
        'provider_reference' => 'ABC123',
        'ticket_number' => '6071234567890',
        'item_details' => [
            'airline_code' => 'YI',
            'rloc' => 'ABC123',
            'is_voidable' => true,
            'tickets' => [
                [
                    'ticket_number' => '6071234567890',
                    'issue_date' => $issueDate,
                ],
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

test('voiding a same-day issued pnr removes tickets, deposits wallet, and logs status', function () {
    global $state;

    Queue::fake();

    [$order, $item] = seedIssuedOrder($state['user'], now()->toDateString());

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('void')
        ->once()
        ->with('ABC123')
        ->andReturn('<PNR RLOC="ABC123"><Itinerary from="MJI" to="IST" date="2026-04-24" departure="10:00" arrival="12:00" flight="123" class="Y" /></PNR>');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->from($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->post($baseUrl.route('tickets.void', ['booking' => $order->id, 'ticket' => $item->id], false))
        ->assertRedirect()
        ->assertSessionHas('success');

    $item->refresh();
    $order->refresh();

    expect($item->status)->toBe('voided')
        ->and($item->ticket_number)->toBeNull()
        ->and(data_get($item->item_details, 'tickets'))->toBe([])
        ->and($order->status)->toBe('voided')
        ->and((float) $order->amount_refunded)->toBe(500.0);

    $wallet = $state['user']->getOrCreateCurrencyWallet('LYD');

    $this->assertDatabaseHas('transactions', [
        'wallet_id' => $wallet->id,
        'type' => 'deposit',
        'amount' => 50000,
    ]);

    $this->assertDatabaseHas('order_status_log', [
        'order_id' => $order->id,
        'old_status' => 'paid',
        'new_status' => 'voided',
        'user_id' => $state['user']->id,
    ]);

    Queue::assertPushed(UpdateAirlineBalanceJob::class, function (UpdateAirlineBalanceJob $job) use ($state): bool {
        if ($job->tenantProviderId !== $state['provider']->id) {
            return false;
        }

        if (! $job->delay instanceof \DateTimeInterface) {
            return false;
        }

        $seconds = $job->delay->getTimestamp() - now()->getTimestamp();

        return $seconds >= 590 && $seconds <= 610;
    });
});

test('voiding is rejected when ticket issue date is not today', function () {
    global $state;

    [$order, $item] = seedIssuedOrder($state['user'], now()->subDay()->toDateString());

    $providerMock = \Mockery::mock();
    $providerMock->shouldNotReceive('void');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->from($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->post($baseUrl.route('tickets.void', ['booking' => $order->id, 'ticket' => $item->id], false))
        ->assertRedirect()
        ->assertSessionHas('error');

    $item->refresh();
    $order->refresh();

    expect($item->status)->toBe('issued')
        ->and($order->status)->toBe('paid')
        ->and((float) $order->amount_refunded)->toBe(0.0);

    $this->assertDatabaseCount('order_status_log', 0);
    $this->assertDatabaseCount('transactions', 0);
});
