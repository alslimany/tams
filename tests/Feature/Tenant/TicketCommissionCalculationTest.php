<?php

use App\Actions\Finance\InitializeTenantLedger;
use App\Actions\Finance\PostToLedger;
use App\Models\Airport;
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
        'id' => 'ticket-commission-'.Str::random(4),
        'company_name' => 'Ticket Commission Agency',
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
        'domestic_commission_rate' => 5,
        'international_commission_rate' => 10,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

function seedPendingIssuedOrder(User $user): array
{
    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'ORD-'.Str::upper(Str::random(8)),
        'status' => 'paid',
        'subtotal' => 350,
        'tax_total' => 0,
        'grand_total' => 350,
        'amount_paid' => 350,
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
        'ticket_number' => null,
        'item_details' => [
            'airline_code' => 'YI',
            'rloc' => 'ABC123',
        ],
        'price' => 350,
        'taxes' => 0,
        'total' => 350,
        'currency' => 'LYD',
        'status' => 'confirmed',
        'paid' => 350,
        'remaining' => 0,
    ]);

    return [$order, $item];
}

test('issuing a ticket stores international commission when the pnr marks the itinerary as international', function () {
    global $state;

    [$order, $item] = seedPendingIssuedOrder($state['user']);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('issueTicket')->once()->andReturn('<PNR RLOC="ABC123"></PNR>');
    $providerMock->shouldReceive('retrieveBooking')->once()->andReturn(<<<'XML'
<PNR RLOC="ABC123" CanVoid="True">
    <Names>
        <PAX PaxNo="1" Title="MR" FirstName="JOHN" Surname="DOE" PaxType="AD" />
    </Names>
    <Itinerary>
        <Itin Line="1" AirID="YI" FltNo="0500" Class="L" DepDate="2026-07-07" Depart="MJI" Arrive="IST" Status="HK" PaxQty="1" DepTime="10:00:00" ArrTime="12:00:00" international="1" />
    </Itinerary>
    <FareQuote>
        <FareStore FSID="FQC" Pax="1" Cur="LYD" Total="350.00">
            <SegmentFS Seg="1" Fare="300.00" Tax1="50.00" Tax2="0" Tax3="0" />
        </FareStore>
    </FareQuote>
    <Tickets>
        <TKT Pax="1" TKTID="ETKT" TktNo="6071234567890" Coupon="01" TktFltDate="07JUL2026" TktFltNo="YI0500" TktDepart="MJI" TktArrive="IST" TktBClass="L" IssueDate="06JUL2026" Status="O" SegNo="01" />
    </Tickets>
</PNR>
XML);

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $initializer = \Mockery::mock(InitializeTenantLedger::class);
    $initializer->shouldReceive('execute')->andReturn([
        'created_root' => false,
        'added_accounts' => 0,
        'total_required_accounts' => 0,
    ]);
    app()->instance(InitializeTenantLedger::class, $initializer);

    $ledgerPoster = \Mockery::mock(PostToLedger::class);
    $ledgerPoster->shouldReceive('execute')->once();
    app()->instance(PostToLedger::class, $ledgerPoster);

    // Fund the provider wallet so assertWalletBalanceBeforeIssue passes for own_credentials.
    $state['provider']->getOrCreateCurrencyWallet('LYD')->depositFloat(1000, ['type' => 'seed_provider_balance']);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->post($baseUrl.route('tickets.issue', ['booking' => $order->id], false), [
        'payment_type' => 'airline_token',
    ])->assertRedirect();

    $item->refresh();

    expect((float) $item->agent_commission)->toBe((float) $item->commission_amount)
        ->and((float) $item->net_commission)->toBe((float) $item->agent_commission)
        ->and((float) data_get($item->item_details, 'commission.fare_total'))->toBe(300.0);
});

test('issuing a ticket falls back to airport countries when the pnr does not provide an international flag', function () {
    global $state;

    Airport::query()->create([
        'name' => ['en' => 'Mitiga'],
        'city' => ['en' => 'Tripoli'],
        'country' => ['en' => 'Libya'],
        'iata_code' => 'MJI',
        'data' => ['country_code' => 'LY'],
    ]);

    Airport::query()->create([
        'name' => ['en' => 'Benina'],
        'city' => ['en' => 'Benghazi'],
        'country' => ['en' => 'Libya'],
        'iata_code' => 'BEN',
        'data' => ['country_code' => 'LY'],
    ]);

    [$order, $item] = seedPendingIssuedOrder($state['user']);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('issueTicket')->once()->andReturn('<PNR RLOC="ABC123"></PNR>');
    $providerMock->shouldReceive('retrieveBooking')->once()->andReturn(<<<'XML'
<PNR RLOC="ABC123" CanVoid="True">
    <Names>
        <PAX PaxNo="1" Title="MR" FirstName="JOHN" Surname="DOE" PaxType="AD" />
    </Names>
    <Itinerary>
        <Itin Line="1" AirID="YI" FltNo="0500" Class="L" DepDate="2026-07-07" Depart="MJI" Arrive="BEN" Status="HK" PaxQty="1" DepTime="10:00:00" ArrTime="11:00:00" />
    </Itinerary>
    <FareQuote>
        <FareStore FSID="FQC" Pax="1" Cur="LYD" Total="350.00">
            <SegmentFS Seg="1" Fare="300.00" Tax1="50.00" Tax2="0" Tax3="0" />
        </FareStore>
    </FareQuote>
    <Tickets>
        <TKT Pax="1" TKTID="ETKT" TktNo="6071234567890" Coupon="01" TktFltDate="07JUL2026" TktFltNo="YI0500" TktDepart="MJI" TktArrive="BEN" TktBClass="L" IssueDate="06JUL2026" Status="O" SegNo="01" />
    </Tickets>
</PNR>
XML);

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $initializer = \Mockery::mock(InitializeTenantLedger::class);
    $initializer->shouldReceive('execute')->andReturn([
        'created_root' => false,
        'added_accounts' => 0,
        'total_required_accounts' => 0,
    ]);
    app()->instance(InitializeTenantLedger::class, $initializer);

    $ledgerPoster = \Mockery::mock(PostToLedger::class);
    $ledgerPoster->shouldReceive('execute')->once();
    app()->instance(PostToLedger::class, $ledgerPoster);

    // Fund the provider wallet so assertWalletBalanceBeforeIssue passes for own_credentials.
    $state['provider']->getOrCreateCurrencyWallet('LYD')->depositFloat(1000, ['type' => 'seed_provider_balance']);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->post($baseUrl.route('tickets.issue', ['booking' => $order->id], false), [
        'payment_type' => 'airline_token',
    ])->assertRedirect();

    $item->refresh();

    expect((float) $item->agent_commission)->toBe((float) $item->commission_amount)
        ->and((float) $item->net_commission)->toBe((float) $item->agent_commission);
});
