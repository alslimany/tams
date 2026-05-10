<?php

use App\Actions\Finance\ProcessWalletTransactions;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Tenant;
use App\Models\Tenant\AirlineAccount;
use App\Models\Tenant\AirlineTransaction;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, RefreshDatabase::class);

/** @var array{tenant?: Tenant, user?: User} $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'wallet-tx-'.Str::random(4),
        'company_name' => 'Wallet Transaction Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    $state['tenant'] = $tenant;

    tenancy()->initialize($tenant);

    /** @var User $user */
    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $state['user'] = $user;
});

afterEach(function () {
    global $state;

    tenancy()->end();

    if (isset($state['tenant'])) {
        $state['tenant']->delete();
    }
});

function makeOrder(User $user, string $currency = 'USD'): Order
{
    return Order::create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'ORD'.Str::upper(Str::random(8)),
        'status' => 'issued',
        'subtotal' => 0,
        'tax_total' => 0,
        'grand_total' => 0,
        'amount_paid' => 0,
        'currency' => $currency,
        'payment_method' => 'wallet',
        'payment_reference' => null,
        'contact' => null,
    ]);
}

function makeOrderItem(Order $order, float $totalAmount, float $commissionAmount = 0.0, string $currency = 'USD'): OrderItem
{
    return OrderItem::create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'ticket',
        'product_subtype' => 'segment',
        'provider' => 'videcom',
        'provider_reference' => 'PNR001',
        'item_details' => [
            'passenger_name' => 'Test Passenger',
            'financial_source' => 'master_agency_supply',
            'default_agency_tenant_id' => (string) tenant('id'),
        ],
        'product_details' => [],
        'price' => $totalAmount,
        'net_fare' => $totalAmount - $commissionAmount,
        'taxes' => [],
        'total_tax' => 0,
        'total' => $totalAmount,
        'total_amount' => $totalAmount,
        'commission_amount' => $commissionAmount,
        'net_after_commission' => max($totalAmount - $commissionAmount, 0),
        'agent_commission' => $commissionAmount,
        'currency' => $currency,
        'exchange_rate' => 1,
        'status' => 'issued',
        'transaction_type' => 'issue',
        'paid' => 0,
        'remaining' => $totalAmount,
    ]);
}

test('it withdraws from wallet and stores wallet_transaction_id on each item', function () {
    global $state;

    /** @var User $user */
    $user = $state['user'];

    $wallet = $user->getOrCreateCurrencyWallet('USD');
    $wallet->deposit(50000); // 500.00 USD in cents

    $order = makeOrder($user, 'USD');
    $item = makeOrderItem($order, 100.0, 0.0, 'USD');

    app(ProcessWalletTransactions::class)->execute($order, $user);

    $item->refresh();

    expect($item->wallet_transaction_id)->not->toBeNull();

    $this->assertDatabaseHas('transactions', [
        'wallet_id' => $wallet->id,
        'type' => 'withdraw',
        'amount' => -10000,
    ]);
});

test('it records commission as payable without depositing back to wallet', function () {
    global $state;

    /** @var User $user */
    $user = $state['user'];

    $wallet = $user->getOrCreateCurrencyWallet('USD');
    $wallet->deposit(50000); // 500.00 USD

    $order = makeOrder($user, 'USD');
    $item = makeOrderItem($order, 100.0, 10.0, 'USD');

    app(ProcessWalletTransactions::class)->execute($order, $user);

    $this->assertDatabaseHas('transactions', [
        'wallet_id' => $wallet->id,
        'type' => 'withdraw',
        'amount' => -10000,
    ]);

    $this->assertDatabaseMissing('transactions', [
        'wallet_id' => $wallet->id,
        'type' => 'deposit',
        'amount' => 1000,
    ]);

    // Net wallet movement: full ticket withdrawal only.
    $wallet->refresh();
    expect((int) $wallet->balance)->toBe(50000 - 10000);
});

test('it throws InsufficientWalletBalanceException when balance is too low', function () {
    global $state;

    /** @var User $user */
    $user = $state['user'];

    $wallet = $user->getOrCreateCurrencyWallet('USD');
    // no deposit — balance is 0

    $order = makeOrder($user, 'USD');
    $item = makeOrderItem($order, 100.0, 0.0, 'USD');

    expect(fn () => app(ProcessWalletTransactions::class)->execute($order, $user))
        ->toThrow(InsufficientWalletBalanceException::class);

    $item->refresh();
    expect($item->wallet_transaction_id)->toBeNull();
});

test('it rolls back all wallet withdrawals when a later item fails the balance check', function () {
    global $state;

    /** @var User $user */
    $user = $state['user'];

    $wallet = $user->getOrCreateCurrencyWallet('USD');
    $wallet->deposit(10000); // 100.00 USD — enough for item 1, not item 2

    $order = makeOrder($user, 'USD');
    $item1 = makeOrderItem($order, 60.0, 0.0, 'USD');
    $item2 = makeOrderItem($order, 80.0, 0.0, 'USD');

    expect(fn () => app(ProcessWalletTransactions::class)->execute($order, $user))
        ->toThrow(InsufficientWalletBalanceException::class);

    $item1->refresh();
    $item2->refresh();

    expect($item1->wallet_transaction_id)->toBeNull()
        ->and($item2->wallet_transaction_id)->toBeNull();

    $wallet->refresh();
    expect((int) $wallet->balance)->toBe(10000);
});

test('it skips items that already have a wallet_transaction_id', function () {
    global $state;

    /** @var User $user */
    $user = $state['user'];

    $wallet = $user->getOrCreateCurrencyWallet('USD');
    $wallet->deposit(10000);

    $order = makeOrder($user, 'USD');
    $item = makeOrderItem($order, 50.0, 0.0, 'USD');
    $item->update([
        'item_details' => [
            'passenger_name' => 'Test Passenger',
            'financial_source' => 'own_credentials',
        ],
    ]);

    // Seed a real wallet transaction so the FK is satisfied
    $seedTx = $wallet->deposit(100);
    $item->update(['wallet_transaction_id' => $seedTx->uuid]);

    app(ProcessWalletTransactions::class)->execute($order, $user);

    // Only the seed deposit exists; no withdraw should have been created
    $this->assertDatabaseMissing('transactions', [
        'wallet_id' => $wallet->id,
        'type' => 'withdraw',
    ]);
});

test('it processes items with only a legacy airline_transaction_id', function () {
    global $state;

    /** @var User $user */
    $user = $state['user'];

    $wallet = $user->getOrCreateCurrencyWallet('USD');
    $wallet->deposit(10000);

    $order = makeOrder($user, 'USD');
    $item = makeOrderItem($order, 50.0, 0.0, 'USD');

    $provider = TenantProvider::create([
        'airline_code' => 'YI',
        'airline_name' => 'Yemenia',
        'account_name' => 'Default',
        'provider_type' => 'videcom',
        'is_active' => true,
        'credentials' => [],
    ]);
    $account = AirlineAccount::create(['tenant_provider_id' => $provider->id, 'currency' => 'USD', 'balance' => 0]);
    $airlineTransaction = AirlineTransaction::create([
        'airline_account_id' => $account->id,
        'type' => 'ticket_cost',
        'amount' => -50,
        'balance_after' => -50,
    ]);
    $item->update(['airline_transaction_id' => $airlineTransaction->id]);

    app(ProcessWalletTransactions::class)->execute($order, $user);

    $item->refresh();

    expect($item->wallet_transaction_id)->not->toBeNull();

    $this->assertDatabaseHas('transactions', [
        'wallet_id' => $wallet->id,
        'type' => 'withdraw',
        'amount' => -5000,
    ]);
});
