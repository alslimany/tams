<?php

use Abivia\Ledger\Models\JournalEntry;
use App\Actions\Finance\PostToLedger;
use App\Models\Tenant;
use App\Models\Tenant\AirlineAccount;
use App\Models\Tenant\AirlineTransaction;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, RefreshDatabase::class);

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

/**
 * Build a fake JournalEntry stub with a predictable ID.
 */
function makeFakeJournalEntry(int $id = 9001): JournalEntry
{
    $entry = new JournalEntry;
    $entry->journalEntryId = $id;

    return $entry;
}

test('it posts correct 6-line ledger entry for a flight item with taxes and stores ledger_entry_id', function () {
    global $state;

    /** @var User $user */
    $user = $state['user'];

    $order = makeOrderForLedger($user);
    $walletTransactionUuid = seedWalletTransactionUuid($user, 'USD');

    // net_fare = base fare (100), total_tax = 20, total_amount = 120, commission = 5
    // Expected:
    //   Dr 1310 = 120 (full selling price)
    //   Cr 4100 = 95  (base fare 100 − commission 5)
    //   Cr 4500 = 5   (commission)
    //   Cr 2410 = 20  (taxes)
    //   Dr 5100 = 115 (provider cost = 100 − 5 + 20)
    //   Cr 1210 = 115 (provider wallet)
    $item = makeOrderItemForLedger($order, [
        'wallet_transaction_id' => $walletTransactionUuid,
        'net_fare' => 100.0,
        'total_tax' => 20.0,
        'total_amount' => 120.0,
        'commission_amount' => 5.0,
    ]);

    $capturedDetails = [];
    $capturedJournal = null;

    $mockService = Mockery::mock(LedgerPostingService::class)->makePartial();
    $mockService->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $journal, string $description, string $reference, array $details, bool $clearing) use (&$capturedDetails, &$capturedJournal): JournalEntry {
            $capturedJournal = $journal;
            $capturedDetails = $details;

            return makeFakeJournalEntry(9001);
        });

    app()->instance(LedgerPostingService::class, $mockService);

    app(PostToLedger::class)->execute($order);

    $item->refresh();

    expect($item->ledger_entry_id)->toBe(9001);
    expect($capturedJournal)->toBe('AIR');

    // Dr 1310 — full selling price
    expect($capturedDetails)->toContain(['code' => '1310', 'debit' => '120']);

    // Cr 4100 — base fare net of commission (100 − 5 = 95)
    expect($capturedDetails)->toContain(['code' => '4100', 'credit' => '95']);

    // Cr 4500 — commission
    expect($capturedDetails)->toContain(['code' => '4500', 'credit' => '5']);

    // Cr 2410 — taxes pass-through
    expect($capturedDetails)->toContain(['code' => '2410', 'credit' => '20']);

    // Dr 5100 — provider cost (100 − 5 + 20 = 115)
    expect($capturedDetails)->toContain(['code' => '5100', 'debit' => '115']);

    // Cr 1210 — provider wallet
    expect($capturedDetails)->toContain(['code' => '1210', 'credit' => '115']);
});

test('it skips legacy airline transaction items without wallet transactions', function () {
    global $state;

    /** @var User $user */
    $user = $state['user'];

    $order = makeOrderForLedger($user);

    $item = makeOrderItemForLedger($order, [
        'airline_transaction_id' => seedAirlineTransactionId(),
    ]);

    $mockService = Mockery::mock(LedgerPostingService::class)->makePartial();
    $mockService->shouldReceive('post')->never();

    app()->instance(LedgerPostingService::class, $mockService);

    app(PostToLedger::class)->execute($order, includeOwnCredentials: true);

    $item->refresh();

    expect($item->ledger_entry_id)->toBeNull();
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

    $mockService = Mockery::mock(LedgerPostingService::class)->makePartial();
    $mockService->shouldReceive('post')
        ->once()
        ->andReturn(makeFakeJournalEntry(9001));

    app()->instance(LedgerPostingService::class, $mockService);

    app(PostToLedger::class)->execute($order, includeOwnCredentials: true);

    $item->refresh();

    expect($item->ledger_entry_id)->toBe(9001);
});

test('it uses agent_commission for master_agency_supply items', function () {
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

    $capturedDetails = [];

    $mockService = Mockery::mock(LedgerPostingService::class)->makePartial();
    $mockService->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $journal, string $description, string $reference, array $details, bool $clearing) use (&$capturedDetails): JournalEntry {
            $capturedDetails = $details;

            return makeFakeJournalEntry(9001);
        });

    app()->instance(LedgerPostingService::class, $mockService);

    app(PostToLedger::class)->execute($order);

    $item->refresh();

    expect($item->ledger_entry_id)->toBe(9001);

    // Commission from agent_commission (not commission_amount) posted to 4500
    expect($capturedDetails)->toContain(['code' => '4500', 'credit' => '12.5']);
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

    $callCount = 0;
    $mockService = Mockery::mock(LedgerPostingService::class)->makePartial();
    $mockService->shouldReceive('post')
        ->twice()
        ->andReturnUsing(function () use (&$callCount): JournalEntry {
            $callCount++;
            if ($callCount === 2) {
                throw new RuntimeException('Mock ledger failure on configured call.');
            }

            return makeFakeJournalEntry(9001);
        });

    app()->instance(LedgerPostingService::class, $mockService);

    expect(fn () => app(PostToLedger::class)->execute($order))
        ->toThrow(RuntimeException::class, 'Mock ledger failure on configured call.');

    $itemA->refresh();
    $itemB->refresh();

    expect($itemA->ledger_entry_id)->toBeNull()
        ->and($itemB->ledger_entry_id)->toBeNull();
});
