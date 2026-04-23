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
        'id' => 'order-pnr-'.Str::random(4),
        'company_name' => 'Order PNR Sync Agency',
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
        'number' => 'PNR0001AA',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 100,
        'tax_total' => 0,
        'grand_total' => 100,
        'amount_paid' => 100,
        'currency' => 'LYD',
        'payment_method' => 'airline_token',
        'payment_reference' => 'AAJ6DU',
    ]);

    $state['item'] = OrderItem::query()->create([
        'order_id' => $state['order']->id,
        'type' => 'flight',
        'product_subtype' => 'oneway',
        'provider' => 'videcom',
        'provider_reference' => 'AAJ6DU',
        'ticket_number' => '854 3220420747',
        'item_details' => [
            'airline_code' => 'YI',
            'passenger_name' => 'JOHN DOE',
            'segments' => [],
        ],
        'price' => 100,
        'taxes' => 0,
        'total' => 100,
        'currency' => 'LYD',
        'status' => 'issued',
    ]);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('queryPnr')->once()->with('AAJ6DU')->andReturn(<<<'XML'
<PNR RLOC="AAJ6DU" PNRLocked="False" CanVoid="True" VoidCutoffTime="2026-04-21T22:00">
    <Names>
        <PAX GrpNo="1" GrpPaxNo="1" PaxNo="1" Title="MR" FirstName="JOHN" Surname="DOE" PaxType="AD" Age="" />
    </Names>
    <Itinerary>
        <Itin Line="1" AirID="YI" FltNo="0500" Class="Y" DepDate="2026-04-30" Depart="MJI" Arrive="IST" Status="HK" PaxQty="1" DepTime="20:00:00" ArrTime="22:00:00" Stops="0" Cabin="Y" ClassBand="ECONOMY Y" ClassBandDisplayName="Y" SelectSeat="False" MMBSelectSeat="False" OpenSeating="False" MMBCheckinAllowed="False" />
    </Itinerary>
    <FareQuote>
        <FQItin Seg="1" Cur="LYD" FQI="SITI 1011" Total="100" Fare="80.00" Tax1="20.00" Tax2="0" Tax3="0" />
        <FareStore FSID="FQC" Pax="1" Cur="LYD" Total="100.00">
            <SegmentFS Seg="1" Fare="80.00" Tax1="20.00" Tax2="0" Tax3="0" />
        </FareStore>
    </FareQuote>
    <Payments>
        <FOP Line="1" FOPID="III" PayCur="LYD" PayAmt="100.00" PayRef="SYNC ABC TOURS01012" PNRCur="LYD" PNRAmt="100.00" PNRExRate="1" PayDate="21APR26" />
    </Payments>
    <Tickets>
        <TKT Pax="1" TktNo="854 3220420747" Status="V" TktFltDate="30APR2026" TktFltNo="YI0500" TktDepart="MJI" TktArrive="IST" TktBClass="Y" IssueDate="21APR2026" SegNo="01" Title="MR" Firstname="JOHN" Surname="DOE" HoldPcs="1" HoldWt="20K" HandWt="0K" WebCheckOut="False" />
    </Tickets>
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

test('order show queries pnr and updates item details with normalized pnr json', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->get($baseUrl.route('orders.show', ['order' => $state['order']], false))
        ->assertOk();

    $item = $state['item']->fresh();

    expect($item->status)->toBe('voided')
        ->and(data_get($item->item_details, 'rloc'))->toBe('AAJ6DU')
        ->and(data_get($item->item_details, 'iata'))->toBe('YI')
        ->and(data_get($item->item_details, 'itineraries.0.from'))->toBe('MJI')
        ->and(data_get($item->item_details, 'payments.0.reference'))->toBe('SYNC ABC TOURS01012')
        ->and(data_get($item->item_details, 'tickets.0.ticket_number'))->toBe('854 3220420747')
        ->and(data_get($item->item_details, 'is_issued'))->toBeTrue()
        ->and(data_get($item->item_details, 'pnr_synced_at'))->not->toBeNull();
});
