<?php

use App\Actions\Finance\PostToLedger;
use App\Models\Tenant;
use App\Models\Tenant\AirlineAccount;
use App\Models\Tenant\AirlineTransaction;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Finance\LedgerDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, RefreshDatabase::class);

final class TestLedgerDriver implements LedgerDriver
{
    /** @var array<int, array{source:string, description:string, entries:array<int, array{account:string, direction:string, amount:float}>}> */
    public array $calls = [];

    public ?int $throwOnCall = null;

    private int $nextId = 9000;

    /**
     * @param  array<int, array{account:string, direction:string, amount:float}>  $entries
     */
    public function postOperationJournal(string $source, string $description, array $entries): int
    {
        $this->calls[] = [
            'source' => $source,
            'description' => $description,
            'entries' => $entries,
        ];

        if ($this->throwOnCall !== null && count($this->calls) === $this->throwOnCall) {
            throw new RuntimeException('Mock ledger failure on configured call.');
        }

        $this->nextId++;

        return $this->nextId;
    }
}

/** @var array{tenant?: Tenant, user?: User} $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'ledger-post-'.Str::random(4),
        'company_name' => 'Ledger Posting Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    $state['tenant'] = $tenant;

    tenancy()->initialize($tenant);

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

function makeOrderForLedger(User $user, string $currency = 'USD'): Order
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

function makeOrderItemForLedger(Order $order, array $overrides = []): OrderItem
{
    return OrderItem::create(array_merge([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'flight',
        'product_subtype' => 'segment',
        'provider' => 'videcom',
        'provider_reference' => 'PNR-LDG',
        'item_details' => ['passenger_name' => 'Ledger Passenger'],
        'product_details' => [],
        'price' => 120.0,
        'net_fare' => 100.0,
        'taxes' => [
            ['code' => 'ST', 'amount' => 20.0, 'description' => 'Sales Tax'],
        ],
        'total_tax' => 20.0,
        'total' => 120.0,
        'total_amount' => 120.0,
        'commission_amount' => 5.0,
        'net_after_commission' => 95.0,
        'agent_commission' => 5.0,
        'currency' => 'USD',
        'exchange_rate' => 1,
        'status' => 'issued',
        'transaction_type' => 'issue',
        'paid' => 0,
        'remaining' => 120.0,
    ], $overrides));
}

function seedWalletTransactionUuid(User $user, string $currency = 'USD'): string
{
    $wallet = $user->getOrCreateCurrencyWallet($currency);
    $transaction = $wallet->deposit(1000);

    return $transaction->uuid;
}

function seedAirlineTransactionId(): int
{
    $provider = TenantProvider::create([
        'airline_code' => 'YI',
        'airline_name' => 'Yemenia',
        'account_name' => 'Default',
        'provider_type' => 'videcom',
        'is_active' => true,
        'credentials' => [],
    ]);

    $account = AirlineAccount::create([
        'tenant_provider_id' => $provider->id,
        'currency' => 'USD',
        'balance' => 0,
    ]);

    $transaction = AirlineTransaction::create([
        'airline_account_id' => $account->id,
        'type' => 'ticket_cost',
        'amount' => -120,
        'balance_after' => -120,
    ]);

    return $transaction->id;
}

test('it posts ledger entries for master supply items and stores ledger_entry_id', function () {
    global $state;

    /** @var User $user */
    $user = $state['user'];

    $order = makeOrderForLedger($user);
    $walletTransactionUuid = seedWalletTransactionUuid($user, 'USD');

    $item = makeOrderItemForLedger($order, [
        'wallet_transaction_id' => $walletTransactionUuid,
    ]);

    $driver = new TestLedgerDriver;
    app()->instance(LedgerDriver::class, $driver);

    app(PostToLedger::class)->execute($order);

    $item->refresh();

    expect($item->ledger_entry_id)->toBe(9001)
        ->and($driver->calls)->toHaveCount(1)
        ->and($driver->calls[0]['source'])->toBe('order_'.$order->id)
        ->and($driver->calls[0]['description'])->toBe("Sale of {$item->product_type} for order {$order->number}")
        ->and($driver->calls[0]['entries'])->toContain([
            'account' => '1300',
            'direction' => 'debit',
            'amount' => 120.0,
        ])
        ->and($driver->calls[0]['entries'])->toContain([
            'account' => '3100',
            'direction' => 'credit',
            'amount' => 100.0,
        ])
        ->and($driver->calls[0]['entries'])->toContain([
            'account' => '2200_ST',
            'direction' => 'credit',
            'amount' => 20.0,
        ])
        ->and($driver->calls[0]['entries'])->toContain([
            'account' => '6100',
            'direction' => 'debit',
            'amount' => 5.0,
        ])
        ->and($driver->calls[0]['entries'])->toContain([
            'account' => '2300',
            'direction' => 'credit',
            'amount' => 5.0,
        ]);
});

test('it skips legacy airline transaction items without wallet transactions', function () {
    global $state;

    /** @var User $user */
    $user = $state['user'];

    $order = makeOrderForLedger($user);

    $item = makeOrderItemForLedger($order, [
        'airline_transaction_id' => seedAirlineTransactionId(),
    ]);

    $driver = new TestLedgerDriver;
    app()->instance(LedgerDriver::class, $driver);

    app(PostToLedger::class)->execute($order, includeOwnCredentials: true);

    $item->refresh();

    expect($item->ledger_entry_id)->toBeNull()
        ->and($driver->calls)->toHaveCount(0);
});

test('it posts own credentials items after provider wallet transaction is linked', function () {
    global $state;

    /** @var User $user */
    $user = $state['user'];

    $order = makeOrderForLedger($user);
    $walletTransactionUuid = seedWalletTransactionUuid($user, 'USD');

    $item = makeOrderItemForLedger($order, [
        'wallet_transaction_id' => $walletTransactionUuid,
        'item_details' => ['financial_source' => 'own_credentials'],
        'airline_transaction_id' => null,
    ]);

    $driver = new TestLedgerDriver;
    app()->instance(LedgerDriver::class, $driver);

    app(PostToLedger::class)->execute($order, includeOwnCredentials: true);

    $item->refresh();

    expect($item->ledger_entry_id)->toBe(9001)
        ->and($driver->calls)->toHaveCount(1);
});

test('it posts commission payable for master supply using agent_commission', function () {
    global $state;

    /** @var User $user */
    $user = $state['user'];

    $order = makeOrderForLedger($user);
    $walletTransactionUuid = seedWalletTransactionUuid($user, 'USD');

    $item = makeOrderItemForLedger($order, [
        'wallet_transaction_id' => $walletTransactionUuid,
        'item_details' => ['financial_source' => 'master_agency_supply'],
        'commission_amount' => 0,
        'agent_commission' => 12.5,
        'net_commission' => 12.5,
    ]);

    $driver = new TestLedgerDriver;
    app()->instance(LedgerDriver::class, $driver);

    app(PostToLedger::class)->execute($order);

    $item->refresh();

    expect($item->ledger_entry_id)->toBe(9001)
        ->and($driver->calls)->toHaveCount(1)
        ->and($driver->calls[0]['entries'])->toContain([
            'account' => '6100',
            'direction' => 'debit',
            'amount' => 12.5,
        ])
        ->and($driver->calls[0]['entries'])->toContain([
            'account' => '2300',
            'direction' => 'credit',
            'amount' => 12.5,
        ]);
});

test('it rolls back all item ledger updates when posting fails', function () {
    global $state;

    /** @var User $user */
    $user = $state['user'];

    $order = makeOrderForLedger($user);

    $itemA = makeOrderItemForLedger($order, [
        'wallet_transaction_id' => seedWalletTransactionUuid($user, 'USD'),
        'provider_reference' => 'PNR-A',
    ]);

    $itemB = makeOrderItemForLedger($order, [
        'wallet_transaction_id' => seedWalletTransactionUuid($user, 'USD'),
        'provider_reference' => 'PNR-B',
    ]);

    $driver = new TestLedgerDriver;
    $driver->throwOnCall = 2;
    app()->instance(LedgerDriver::class, $driver);

    expect(fn () => app(PostToLedger::class)->execute($order))
        ->toThrow(RuntimeException::class, 'Mock ledger failure on configured call.');

    $itemA->refresh();
    $itemB->refresh();

    expect($driver->calls)->toHaveCount(2)
        ->and($itemA->ledger_entry_id)->toBeNull()
        ->and($itemB->ledger_entry_id)->toBeNull();
});
