<?php

use App\Actions\Finance\InitializeTenantLedger;
use App\Actions\Finance\PostToLedger;
use App\Jobs\UpdateAirlineBalanceJob;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Airline\AirlineProviderInterface;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
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
    $providerMock->shouldReceive('retrieveBooking')->once()->andReturn(<<<'XML'
<PNR RLOC="ABC123" CanVoid="True">
    <Names>
        <PAX PaxNo="1" Title="MR" FirstName="JOHN" Surname="DOE" PaxType="AD" />
    </Names>
    <Itinerary>
        <Itin Line="1" AirID="YI" FltNo="0500" Class="L" DepDate="2026-07-07" Depart="MJI" Arrive="BEN" Status="HK" PaxQty="1" DepTime="10:00:00" ArrTime="11:00:00" />
    </Itinerary>
    <FareQuote>
        <FareStore FSID="FQC" Pax="1" Cur="LYD" Total="500.00">
            <SegmentFS Seg="1" Fare="450.00" Tax1="50.00" Tax2="0" Tax3="0" />
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

    $customerWallet = $state['user']->getOrCreateCurrencyWallet('LYD');
    $customerWallet->depositFloat(700, ['type' => 'seed_customer_balance']);
    $customerWithdrawal = $customerWallet->withdrawFloat(500, [
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'type' => 'ticket_purchase',
    ]);

    $providerWallet = $state['provider']->getOrCreateCurrencyWallet('LYD');
    $providerWallet->depositFloat(1000, ['type' => 'seed_provider_balance']);
    $providerWithdrawal = $providerWallet->withdrawFloat(450, [
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'type' => 'provider_issuance_cost',
    ]);

    $item->update([
        'wallet_transaction_id' => $customerWithdrawal->uuid,
        'item_details' => array_merge((array) $item->item_details, [
            'provider_wallet_transaction_id' => $providerWithdrawal->uuid,
            'provider_wallet_withdrawal_amount' => 450,
        ]),
    ]);

    $providerMock = \Mockery::mock(AirlineProviderInterface::class);
    $providerMock->shouldReceive('refund')->once()->with('6071234567890')->andReturn('<PNR RLOC="ABC123"></PNR>');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->post($baseUrl.route('tickets.refund', ['booking' => $order->id, 'ticket' => $item->id], false), [
        'penalty_amount' => 50,
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $refreshedItem = $item->fresh();
    $refundTransaction = WalletTransaction::query()
        ->where('uuid', (string) data_get($refreshedItem->item_details, 'refund.customer_wallet_transaction_id'))
        ->first();
    $penaltyTransaction = WalletTransaction::query()
        ->where('uuid', (string) data_get($refreshedItem->item_details, 'refund.penalty_wallet_transaction_id'))
        ->first();
    $providerRefundTransaction = WalletTransaction::query()
        ->where('uuid', (string) data_get($refreshedItem->item_details, 'refund.provider_wallet_transaction_id'))
        ->first();

    expect($refreshedItem->status)->toBe('refunded')
        ->and($refundTransaction)->not->toBeNull()
        ->and((int) $refundTransaction->amount)->toBe(50000)
        ->and($penaltyTransaction)->not->toBeNull()
        ->and((int) $penaltyTransaction->amount)->toBe(-5000)
        ->and($providerRefundTransaction)->not->toBeNull()
        ->and((int) $providerRefundTransaction->wallet_id)->toBe((int) $providerWallet->id)
        ->and((int) $providerRefundTransaction->amount)->toBe(45000)
        ->and((float) $order->fresh()->amount_refunded)->toBe(450.0)
        ->and((float) $customerWallet->fresh()->balanceFloat)->toBe(650.0)
        ->and((float) $providerWallet->fresh()->balanceFloat)->toBe(1000.0);

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

test('issuing ticket rolls back and voids pnr when financial actions fail', function () {
    global $state;

    Queue::fake();

    [$order, $item] = seedIssuedOrderForBalanceDispatch($state['user']);

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
        <FareStore FSID="FQC" Pax="1" Cur="LYD" Total="500.00">
            <SegmentFS Seg="1" Fare="450.00" Tax1="50.00" Tax2="0" Tax3="0" />
        </FareStore>
    </FareQuote>
    <Tickets>
        <TKT Pax="1" TKTID="ETKT" TktNo="6071234567890" Coupon="01" TktFltDate="07JUL2026" TktFltNo="YI0500" TktDepart="MJI" TktArrive="BEN" TktBClass="L" IssueDate="06JUL2026" Status="O" SegNo="01" />
    </Tickets>
</PNR>
XML);
    $providerMock->shouldReceive('void')->once()->withAnyArgs()->andReturn('<PNR RLOC="ABC123"></PNR>');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $initializer = \Mockery::mock(InitializeTenantLedger::class);
    $initializer->shouldReceive('execute')->once()->andReturn([
        'created_root' => false,
        'added_accounts' => 0,
        'total_required_accounts' => 0,
    ]);
    app()->instance(InitializeTenantLedger::class, $initializer);

    $ledgerPoster = \Mockery::mock(PostToLedger::class);
    $ledgerPoster->shouldReceive('execute')->once()->andThrow(new RuntimeException('Financial posting failed.'));
    app()->instance(PostToLedger::class, $ledgerPoster);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->post($baseUrl.route('tickets.issue', ['booking' => $order->id], false), [
        'payment_type' => 'airline_token',
    ])->assertRedirect()->assertSessionHas('error');

    $order->refresh();
    $item->refresh();

    expect(Order::query()->count())->toBe(1)
        ->and((int) Order::query()->where('parent_id', $order->id)->count())->toBe(0)
        ->and($order->status)->toBe('confirmed')
        ->and($item->status)->toBe('issued');

    Queue::assertNotPushed(UpdateAirlineBalanceJob::class);
});
