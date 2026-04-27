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

    $masterTenant = Tenant::create([
        'id' => 'master-src-'.Str::random(4),
        'company_name' => 'Master Source Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);
    $masterTenant->domains()->create(['domain' => $masterTenant->id.'.localhost']);

    $buyerTenant = Tenant::create([
        'id' => 'buyer-src-'.Str::random(4),
        'company_name' => 'Buyer Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);
    $buyerTenant->domains()->create(['domain' => $buyerTenant->id.'.localhost']);

    $state['master'] = $masterTenant;
    $state['buyer'] = $buyerTenant;

    $masterTenant->run(function (): void {
        TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'YI',
            'airline_name' => 'Oya',
            'account_name' => 'Default',
            'is_active' => true,
            'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
        ]);
    });

    tenancy()->initialize($buyerTenant);

    $state['user'] = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $state['order'] = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $state['user']->id,
        'number' => 'MSRC0001AA',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 100,
        'tax_total' => 0,
        'grand_total' => 100,
        'amount_paid' => 100,
        'currency' => 'LYD',
        'payment_method' => 'default_agency_supply',
        'payment_reference' => 'AAMSRC',
    ]);

    $state['item'] = OrderItem::query()->create([
        'order_id' => $state['order']->id,
        'type' => 'flight',
        'product_subtype' => 'oneway',
        'provider' => 'videcom',
        'provider_reference' => 'AAMSRC',
        'ticket_number' => '854 1111111111',
        'item_details' => [
            'airline_code' => 'YI',
            'financial_source' => 'master_agency_supply',
            'default_agency_tenant_id' => $masterTenant->id,
            'passenger_name' => 'JOHN DOE',
        ],
        'price' => 100,
        'taxes' => 0,
        'total' => 100,
        'currency' => 'LYD',
        'status' => 'issued',
    ]);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('queryPnr')->once()->with('AAMSRC')->andReturn(<<<'XML'
<PNR RLOC="AAMSRC" PNRLocked="False" CanVoid="True">
    <Names>
        <PAX PaxNo="1" Title="MR" FirstName="JOHN" Surname="DOE" PaxType="AD" />
    </Names>
    <Itinerary>
        <Itin Line="1" AirID="YI" FltNo="0500" Class="Y" DepDate="2026-04-30" Depart="MJI" Arrive="IST" Status="HK" PaxQty="1" DepTime="20:00:00" ArrTime="22:00:00" />
    </Itinerary>
    <Tickets>
        <TKT Pax="1" TktNo="854 1111111111" Status="O" />
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

    $state['buyer']->delete();
    $state['master']->delete();

    \Mockery::close();
});

test('order show sync queries pnr through master source tenant provider for master-supply items', function () {
    global $state;

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['buyer']->domains->first()->domain;

    $this->get($baseUrl.route('orders.show', ['order' => $state['order']], false))
        ->assertOk();

    $item = $state['item']->fresh();

    expect($item->ticket_number)->toBe('854 1111111111')
        ->and($item->status)->toBe('issued')
        ->and(data_get($item->item_details, 'pnr_synced_at'))->not->toBeNull()
        ->and(data_get($item->item_details, 'financial_source'))->toBe('master_agency_supply')
        ->and(data_get($item->item_details, 'default_agency_tenant_id'))->toBe($state['master']->id);
});
