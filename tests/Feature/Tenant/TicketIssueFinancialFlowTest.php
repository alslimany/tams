<?php

use App\Actions\Finance\InitializeTenantLedger;
use App\Actions\Finance\PostToLedger;
use App\Actions\Finance\ProcessWalletTransactions;
use App\DTOs\Finance\FinancialSourceData;
use App\Models\DefaultAgencySetting;
use App\Models\Tenant;
use App\Models\Tenant\AgencySetting;
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
        'id' => 'ticket-fin-flow-'.Str::random(4),
        'company_name' => 'Ticket Financial Flow Agency',
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

function seedPendingOrder(User $user, string $pnr = 'ABC123'): array
{
    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'ORD-'.Str::upper(Str::random(8)),
        'status' => 'confirmed',
        'subtotal' => 350,
        'tax_total' => 0,
        'grand_total' => 350,
        'amount_paid' => 350,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'airline_token',
        'payment_reference' => $pnr,
        'contact' => ['email' => 'john@example.com'],
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'type' => 'flight_ticket',
        'product_subtype' => 'economy',
        'provider' => 'videcom',
        'provider_reference' => $pnr,
        'ticket_number' => null,
        'item_details' => [
            'airline_code' => 'YI',
            'rloc' => $pnr,
        ],
        'price' => 350,
        'net_fare' => 350,
        'taxes' => 0,
        'total' => 350,
        'total_amount' => 350,
        'currency' => 'LYD',
        'status' => 'confirmed',
        'paid' => 350,
        'remaining' => 0,
    ]);

    return [$order, $item];
}

function mockProviderForIssue(string $pnr = 'ABC123'): object
{
    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('issueTicket')->once()->andReturn('<PNR RLOC="'.$pnr.'"></PNR>');
    $providerMock->shouldReceive('retrieveBooking')->once()->andReturn(<<<XML
<PNR RLOC="{$pnr}" CanVoid="True">
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

    return $providerMock;
}

test('issuing a ticket with master agency supply processes wallet transactions and posts to ledger', function () {
    global $state;

    // Explicitly disable own credentials so financial source = master_agency_supply.
    $state['tenant']->update([
        'settings' => [
            'finance' => [
                'use_own_airline_credentials' => false,
            ],
        ],
    ]);

    AgencySetting::current()->update([
        'master_commission_percent' => 10,
    ]);

    [$order, $item] = seedPendingOrder($state['user']);

    $providerMock = mockProviderForIssue();

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

    // Fund the wallet so ProcessWalletTransactions can withdraw.
    $wallet = $state['user']->getOrCreateCurrencyWallet('LYD');
    $wallet->deposit(50000, ['type' => 'initial_fund']);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->post($baseUrl.route('tickets.issue', ['booking' => $order->id], false), [
        'payment_type' => 'airline_token',
    ])->assertRedirect();

    // The issued order is a child of the original booking.
    $issuedOrder = Order::query()->where('parent_id', $order->id)->first();
    expect($issuedOrder)->not->toBeNull();

    $issuedItem = $issuedOrder->items->first();

    // Financial source should be master_agency_supply.
    expect(data_get($issuedItem->item_details, 'financial_source'))->toBe('master_agency_supply');

    // In master-supply mode, commission is tracked as payable via agent_commission.
    expect((float) $issuedItem->commission_amount)->toBe(0.0)
        ->and((float) $issuedItem->agent_commission)->toBe(35.0)
        ->and((float) $issuedItem->net_commission)->toBe(35.0);

    // Wallet should have been debited for the ticket purchase.
    $wallet->refresh();
    expect((int) $wallet->balance)->toBeLessThan(50000);

    // A wallet transaction for ticket_purchase should exist.
    $this->assertDatabaseHas('transactions', [
        'wallet_id' => $wallet->id,
        'type' => 'withdraw',
    ]);

    // Status log should be created on the issued order.
    $this->assertDatabaseHas('order_status_log', [
        'order_id' => $issuedOrder->id,
        'new_status' => 'issued',
        'user_id' => $state['user']->id,
    ]);
});

test('issuing a ticket with own credentials creates airline transactions and posts to ledger', function () {
    global $state;

    // Default tenant settings have usesOwnAirlineCredentials = true.
    [$order, $item] = seedPendingOrder($state['user']);

    $providerMock = mockProviderForIssue();

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

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->post($baseUrl.route('tickets.issue', ['booking' => $order->id], false), [
        'payment_type' => 'airline_token',
    ])->assertRedirect();

    // The issued order is a child of the original booking.
    $issuedOrder = Order::query()->where('parent_id', $order->id)->first();
    expect($issuedOrder)->not->toBeNull();

    $issuedItem = $issuedOrder->items->first();

    // Financial source should be own_credentials.
    expect(data_get($issuedItem->item_details, 'financial_source'))->toBe('own_credentials');

    // Airline transaction should have been created.
    expect($issuedItem->airline_transaction_id)->not->toBeNull();

    // No wallet withdrawal should have occurred.
    expect($issuedItem->wallet_transaction_id)->toBeNull();

    // Airline account balance should be debited.
    $this->assertDatabaseHas('airline_accounts', [
        'tenant_provider_id' => $state['provider']->id,
        'currency' => 'LYD',
    ]);

    $this->assertDatabaseHas('airline_transactions', [
        'order_item_id' => $issuedItem->id,
        'type' => 'ticket_cost',
    ]);

    // Status log should be created on the issued order.
    $this->assertDatabaseHas('order_status_log', [
        'order_id' => $issuedOrder->id,
        'new_status' => 'issued',
        'user_id' => $state['user']->id,
    ]);
});

test('failed financial processing rolls back and attempts PNR void', function () {
    global $state;

    // Disable own credentials so wallet path is taken.
    $state['tenant']->update([
        'settings' => [
            'finance' => [
                'use_own_airline_credentials' => false,
            ],
        ],
    ]);

    [$order, $item] = seedPendingOrder($state['user']);

    $providerMock = mockProviderForIssue();
    // The void should be called when the transaction fails.
    $providerMock->shouldReceive('void')->once()->with('ABC123');

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

    // Make PostToLedger throw to simulate a failure after wallet processing.
    $ledgerPoster = \Mockery::mock(PostToLedger::class);
    $ledgerPoster->shouldReceive('execute')->once()->andThrow(new \RuntimeException('Ledger post failed'));
    app()->instance(PostToLedger::class, $ledgerPoster);

    // Fund the wallet so ProcessWalletTransactions can proceed (but PostToLedger will fail).
    $wallet = $state['user']->getOrCreateCurrencyWallet('LYD');
    $wallet->deposit(50000, ['type' => 'initial_fund']);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->post($baseUrl.route('tickets.issue', ['booking' => $order->id], false), [
        'payment_type' => 'airline_token',
    ])->assertRedirect()->assertSessionHas('error');

    // The original order should NOT have been updated (rolled back).
    $order->refresh();
    expect($order->status)->toBe('confirmed');

    // No child order should exist (rolled back).
    expect(Order::query()->where('parent_id', $order->id)->exists())->toBeFalse();

    // No status log should exist (rolled back).
    $this->assertDatabaseMissing('order_status_log', [
        'order_id' => $order->id,
        'new_status' => 'issued',
    ]);
});

test('DetermineFinancialSource returns master_agency_supply when tenant disables own credentials', function () {
    global $state;

    $state['tenant']->update([
        'settings' => [
            'finance' => [
                'use_own_airline_credentials' => false,
            ],
        ],
    ]);

    $action = app(\App\Actions\Finance\DetermineFinancialSource::class);

    $result = $action->execute('YI', 'LYD');

    expect($result)->toBeInstanceOf(FinancialSourceData::class)
        ->and($result->type)->toBe('master_agency_supply')
        ->and($result->usesMasterAgencySupply())->toBeTrue()
        ->and($result->usesOwnCredentials())->toBeFalse();
});

test('DetermineFinancialSource returns own_credentials when tenant uses own credentials', function () {
    global $state;

    // Default is usesOwnAirlineCredentials = true.
    $action = app(\App\Actions\Finance\DetermineFinancialSource::class);

    $result = $action->execute('YI', 'LYD');

    expect($result)->toBeInstanceOf(FinancialSourceData::class)
        ->and($result->type)->toBe('own_credentials')
        ->and($result->usesOwnCredentials())->toBeTrue()
        ->and($result->usesMasterAgencySupply())->toBeFalse();
});

test('DetermineFinancialSource uses per-agency master commission percent only', function () {
    global $state;

    $defaultAgency = Tenant::create([
        'id' => 'default-'.Str::random(4),
        'company_name' => 'Default Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
        'is_default_agency' => true,
    ]);

    DefaultAgencySetting::updateOrCreate(
        ['default_agency_tenant_id' => $defaultAgency->id],
        ['master_commission_percent' => 12.5],
    );

    AgencySetting::current()->update([
        'force_use_default_agency' => true,
        'default_agency_tenant_id' => $defaultAgency->id,
        'master_commission_percent' => 0,
    ]);

    $action = app(\App\Actions\Finance\DetermineFinancialSource::class);

    $result = $action->execute('YI', 'LYD');

    expect($result)->toBeInstanceOf(FinancialSourceData::class)
        ->and($result->type)->toBe('master_agency_supply')
        ->and($result->masterCommissionRate)->toBe(0.0);
});

test('ProcessWalletTransactions only processes master_agency_supply items', function () {
    global $state;

    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $state['user']->id,
        'number' => 'ORD-'.Str::upper(Str::random(8)),
        'status' => 'issued',
        'subtotal' => 700,
        'tax_total' => 0,
        'grand_total' => 700,
        'amount_paid' => 700,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'airline_token',
        'payment_reference' => 'MIX001',
        'contact' => ['email' => 'john@example.com'],
    ]);

    // Item 1: master_agency_supply — should be processed by wallet.
    $masterItem = OrderItem::create([
        'order_id' => $order->id,
        'type' => 'flight_ticket',
        'product_subtype' => 'economy',
        'provider' => 'videcom',
        'provider_reference' => 'MIX001',
        'ticket_number' => '6071111111111',
        'item_details' => [
            'airline_code' => 'YI',
            'rloc' => 'MIX001',
            'financial_source' => 'master_agency_supply',
            'passenger_name' => 'JOHN DOE',
        ],
        'price' => 350,
        'net_fare' => 350,
        'taxes' => 0,
        'total' => 350,
        'total_amount' => 350,
        'currency' => 'LYD',
        'status' => 'issued',
        'commission_amount' => 35,
        'paid' => 350,
        'remaining' => 0,
    ]);

    // Item 2: own_credentials — should NOT be processed by wallet.
    $ownItem = OrderItem::create([
        'order_id' => $order->id,
        'type' => 'flight_ticket',
        'product_subtype' => 'economy',
        'provider' => 'videcom',
        'provider_reference' => 'MIX001',
        'ticket_number' => '6072222222222',
        'item_details' => [
            'airline_code' => 'YI',
            'rloc' => 'MIX001',
            'financial_source' => 'own_credentials',
            'passenger_name' => 'JANE DOE',
        ],
        'price' => 350,
        'net_fare' => 350,
        'taxes' => 0,
        'total' => 350,
        'total_amount' => 350,
        'currency' => 'LYD',
        'status' => 'issued',
        'commission_amount' => 35,
        'paid' => 350,
        'remaining' => 0,
    ]);

    // Fund the wallet.
    $wallet = $state['user']->getOrCreateCurrencyWallet('LYD');
    $wallet->deposit(50000, ['type' => 'initial_fund']);

    app(ProcessWalletTransactions::class)->execute($order, $state['user']);

    $masterItem->refresh();
    $ownItem->refresh();

    // master_agency_supply item should have a wallet transaction.
    expect($masterItem->wallet_transaction_id)->not->toBeNull();

    // own_credentials item should NOT have a wallet transaction.
    expect($ownItem->wallet_transaction_id)->toBeNull();
});

test('ticket issuance is rejected before provider call when wallet is insufficient', function () {
    global $state;

    $state['tenant']->update([
        'settings' => [
            'finance' => [
                'use_own_airline_credentials' => false,
            ],
        ],
    ]);

    [$order] = seedPendingOrder($state['user'], 'INSUF1');

    $providerMock = \Mockery::mock();
    $providerMock->shouldNotReceive('issueTicket');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->post($baseUrl.route('tickets.issue', ['booking' => $order->id], false), [
        'payment_type' => 'airline_token',
    ])->assertRedirect()->assertSessionHas('error');

    expect(Order::query()->where('parent_id', $order->id)->exists())->toBeFalse();
});
