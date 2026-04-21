<?php

use App\Models\Tenant;
use App\Models\Tenant\AirlineAccount;
use App\Models\Tenant\AirlineTransaction;
use App\Models\Tenant\Booking;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $state['tenant'] = Tenant::create([
        'id' => 'order-flow-'.Str::random(4),
        'company_name' => 'Order Flow Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $state['tenant']->domains()->create([
        'domain' => $state['tenant']->id.'.localhost',
    ]);

    tenancy()->initialize($state['tenant']);

    $state['manager'] = User::factory()->create([
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

    $customer = Customer::create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'phone' => '1234567',
    ]);

    $state['booking'] = Booking::create([
        'customer_id' => $customer->id,
        'pnr' => 'ABC123',
        'tenant_provider_id' => $state['provider']->id,
        'status' => 'confirmed',
        'total_price' => 500,
        'currency' => 'LYD',
        'created_by' => $state['manager']->id,
    ]);

    $state['xmlResponse'] = <<<'XML'
<PNR RLOC="ABC123">
    <Names>
        <PAX PaxNo="1" FirstName="JOHN" Surname="DOE" />
    </Names>
    <Itinerary>
        <Itin Line="1" AirID="YI" FltNo="0500" Class="Y" DepDate="07JUL2026" Depart="MJI" Arrive="IST" />
    </Itinerary>
    <FareQuote>
        <FareStore Pax="1" Cur="LYD" Total="500.00" />
        <FareTax>
            <PaxTax Pax="1" Code="XT" Cur="LYD" Amnt="50.00" />
        </FareTax>
    </FareQuote>
    <Payments>
        <FOP FOPID="MI" PayCur="LYD" PayAmt="500.00" PayRef="PAY-REF-001" />
    </Payments>
    <Tickets>
        <TKT Pax="1" TktNo="854 3220420747" TktFltDate="07JUL2026" TktFltNo="YI0500" TktDepart="MJI" TktArrive="IST" TktBClass="Y" IssueDate="06JUL2026" Status="F" SegNo="1" />
    </Tickets>
</PNR>
XML;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

test('ticket issuance creates order and airline account transaction when tenant uses own credentials', function () {
    global $state;

    $state['tenant']->update([
        'settings' => [
            'finance' => ['use_own_airline_credentials' => true],
        ],
    ]);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('issueTicket')->once()->andReturn($state['xmlResponse']);
    $providerMock->shouldReceive('retrieveBooking')->once()->andReturn($state['xmlResponse']);

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['manager']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->post($baseUrl.route('tickets.issue', ['booking' => $state['booking']], false));

    $order = Order::query()->first();

    expect($order)->not->toBeNull();

    $response->assertRedirect(route('tickets.completed', ['booking' => $state['booking'], 'order' => $order->id], false));

    $item = OrderItem::query()->first();

    expect($item)->not->toBeNull();
    expect(AirlineAccount::query()->count())->toBe(1);
    expect(AirlineTransaction::query()->count())->toBe(1);
    expect($item->airline_transaction_id)->not->toBeNull();
    expect($item->wallet_transaction_id)->toBeNull();
});

test('ticket issuance creates order and wallet transaction when tenant uses master supply', function () {
    global $state;

    $state['tenant']->update([
        'settings' => [
            'finance' => ['use_own_airline_credentials' => false],
        ],
    ]);

    $wallet = $state['manager']->getOrCreateCurrencyWallet('LYD');
    $wallet->deposit(100000, ['seed' => true]);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('issueTicket')->once()->andReturn($state['xmlResponse']);
    $providerMock->shouldReceive('retrieveBooking')->once()->andReturn($state['xmlResponse']);

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['manager']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->post($baseUrl.route('tickets.issue', ['booking' => $state['booking']], false), [
        'payment_type' => 'wallet',
    ]);

    $order = Order::query()->first();

    expect($order)->not->toBeNull();

    $response->assertRedirect(route('tickets.completed', ['booking' => $state['booking'], 'order' => $order->id], false));

    $item = OrderItem::query()->first();

    expect($item)->not->toBeNull();
    expect($item->wallet_transaction_id)->not->toBeNull();
    expect($item->airline_transaction_id)->toBeNull();

    $walletTransaction = WalletTransaction::query()
        ->where('uuid', $item->wallet_transaction_id)
        ->first();

    expect($walletTransaction)->not->toBeNull();
    expect($walletTransaction->meta['order_id'] ?? null)->toBe($order->id);
    expect((int) ($walletTransaction->meta['order_item_id'] ?? 0))->toBe($item->id);
    expect(AirlineTransaction::query()->count())->toBe(0);
});
