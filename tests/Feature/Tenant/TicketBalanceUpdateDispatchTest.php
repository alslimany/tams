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
        'id' => 'ticket-balance-'.Str::random(4),
        'company_name' => 'Ticket Balance Agency',
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

function seedIssuedOrderForBalanceDispatch(User $user): array
{
    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'ORD-'.Str::upper(Str::random(8)),
        'status' => 'confirmed',
        'subtotal' => 500,
        'tax_total' => 0,
        'grand_total' => 500,
        'amount_paid' => 500,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'airline_token',
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
            'tickets' => [
                [
                    'ticket_number' => '6071234567890',
                    'issue_date' => now()->toDateString(),
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

test('issuing ticket dispatches delayed airline balance update job', function () {
    global $state;

    Queue::fake();

    [$order, $item] = seedIssuedOrderForBalanceDispatch($state['user']);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('issueTicket')->once()->andReturn('<PNR RLOC="ABC123"></PNR>');
    $providerMock->shouldReceive('retrieveBooking')->once()->andReturn('<PNR RLOC="ABC123"></PNR>');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->post($baseUrl.route('tickets.issue', ['booking' => $order->id], false), [
        'payment_type' => 'airline_token',
    ])->assertRedirect();

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

test('refund ticket dispatches delayed airline balance update job', function () {
    global $state;

    Queue::fake();

    [$order, $item] = seedIssuedOrderForBalanceDispatch($state['user']);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('refund')->once()->with('6071234567890')->andReturn('<PNR RLOC="ABC123"></PNR>');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->post($baseUrl.route('tickets.refund', ['booking' => $order->id, 'ticket' => $item->id], false))
        ->assertRedirect()
        ->assertSessionHas('success');

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
