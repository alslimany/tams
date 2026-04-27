<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'order-no-ticket-'.Str::random(4),
        'company_name' => 'Order No Ticket Sync Agency',
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

    TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'Default',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    $state['order'] = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $state['user']->id,
        'number' => 'PNR0002AA',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 100,
        'tax_total' => 0,
        'grand_total' => 100,
        'amount_paid' => 100,
        'currency' => 'LYD',
        'payment_method' => 'airline_token',
        'payment_reference' => 'AANOTK',
    ]);

    $state['item'] = OrderItem::query()->create([
        'order_id' => $state['order']->id,
        'type' => 'flight',
        'product_subtype' => 'oneway',
        'provider' => 'videcom',
        'provider_reference' => 'AANOTK',
        'ticket_number' => '8540000000001',
        'item_details' => [
            'airline_code' => 'YI',
            'passenger_name' => 'JOHN DOE',
            'tickets' => [
                ['ticket_number' => '8540000000001', 'status' => 'O'],
            ],
            'pnr_synced_at' => now()->subDay()->toIso8601String(),
        ],
        'price' => 100,
        'taxes' => 0,
        'total' => 100,
        'currency' => 'LYD',
        'status' => 'issued',
    ]);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('queryPnr')->once()->with('AANOTK')->andReturn(<<<'XML'
<PNR RLOC="AANOTK" PNRLocked="False">
    <Names>
        <PAX PaxNo="1" Title="MR" FirstName="JOHN" Surname="DOE" PaxType="AD" />
    </Names>
    <Itinerary>
        <Itin Line="1" AirID="YI" FltNo="0500" Class="Y" DepDate="2026-04-30" Depart="MJI" Arrive="IST" Status="HK" PaxQty="1" DepTime="20:00:00" ArrTime="22:00:00" />
    </Itinerary>
</PNR>
XML);

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

test('order show does not update item snapshot when queried pnr has no tickets', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $beforeStatus = (string) $state['item']->status;
    $beforeTicket = (string) $state['item']->ticket_number;
    $beforeDetails = $state['item']->item_details;

    $this->get($baseUrl.route('orders.show', ['order' => $state['order']], false))
        ->assertOk();

    $after = $state['item']->fresh();

    expect((string) $after->status)->toBe($beforeStatus)
        ->and((string) $after->ticket_number)->toBe($beforeTicket)
        ->and($after->item_details)->toBe($beforeDetails);
});
