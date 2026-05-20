<?php

use Abivia\Ledger\Models\JournalEntry;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeRepostTenant(): Tenant
{
    $tenant = Tenant::create([
        'id' => 'repost-'.Str::random(4),
        'company_name' => 'Repost Test Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    return $tenant;
}

function makeRepostOrder(User $user): Order
{
    return Order::create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'RPT'.Str::upper(Str::random(6)),
        'status' => 'issued',
        'subtotal' => 0,
        'tax_total' => 0,
        'grand_total' => 0,
        'amount_paid' => 0,
        'currency' => 'USD',
        'payment_method' => 'wallet',
        'payment_reference' => null,
        'contact' => null,
    ]);
}

function makeRepostItem(Order $order, array $overrides = []): OrderItem
{
    return OrderItem::create(array_merge([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'flight',
        'product_subtype' => 'segment',
        'provider' => 'videcom',
        'provider_reference' => 'RPTPNR',
        'item_details' => ['financial_source' => 'master_agency_supply'],
        'product_details' => [],
        'price' => 350.0,
        'net_fare' => 300.0,
        'taxes' => [['code' => 'ST', 'amount' => 50.0]],
        'total_tax' => 50.0,
        'total' => 350.0,
        'total_amount' => 350.0,
        'commission_amount' => 10.0,
        'agent_commission' => 10.0,
        'net_after_commission' => 290.0,
        'currency' => 'USD',
        'exchange_rate' => 1,
        'status' => 'issued',
        'transaction_type' => 'issue',
        'paid' => 0,
        'remaining' => 350.0,
    ], $overrides));
}

function makeFakeEntry(int $id): JournalEntry
{
    $entry = new JournalEntry;
    $entry->journalEntryId = $id;

    return $entry;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('finance:repost-ledger reverses old entry and posts corrected entry, updating ledger_entry_id', function () {
    $tenant = makeRepostTenant();
    tenancy()->initialize($tenant);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $order = makeRepostOrder($user);
    $item = makeRepostItem($order, ['ledger_entry_id' => 5001]);

    $reversalCalled = false;
    $postCalls = [];

    $mockService = Mockery::mock(LedgerPostingService::class)->makePartial();

    $mockService->shouldReceive('postReversalEntry')
        ->once()
        ->andReturnUsing(function () use (&$reversalCalled): JournalEntry {
            $reversalCalled = true;

            return makeFakeEntry(5002);
        });

    $mockService->shouldReceive('post')
        ->once()
        ->andReturnUsing(function (string $journal, string $description, string $reference, array $details, bool $clearing = false) use (&$postCalls): JournalEntry {
            $postCalls[] = ['journal' => $journal, 'details' => $details];

            return makeFakeEntry(5003);
        });

    $mockService->shouldReceive('revenueAccount')->passthru();
    $mockService->shouldReceive('costAccount')->passthru();
    $mockService->shouldReceive('providerWalletAccount')->passthru();
    $mockService->shouldReceive('resolveJournal')->passthru();

    app()->instance(LedgerPostingService::class, $mockService);

    $this->artisan('finance:repost-ledger', [
        'identifier' => (string) $order->id,
        '--skip-initialize' => true,
    ])->assertSuccessful();

    expect($reversalCalled)->toBeTrue();
    expect($postCalls)->toHaveCount(1);
    expect($postCalls[0]['journal'])->toBe('AIR');

    $details = $postCalls[0]['details'];

    // Dr 1310 — full selling price 350
    expect($details)->toContain(['code' => '1310', 'debit' => '350']);
    // Cr 4100 — base fare 300 − commission 10 = 290
    expect($details)->toContain(['code' => '4100', 'credit' => '290']);
    // Cr 4500 — commission 10
    expect($details)->toContain(['code' => '4500', 'credit' => '10']);
    // Cr 2410 — taxes 50
    expect($details)->toContain(['code' => '2410', 'credit' => '50']);
    // Dr 5100 — provider cost 300 − 10 + 50 = 340
    expect($details)->toContain(['code' => '5100', 'debit' => '340']);
    // Cr 1210 — provider wallet 340
    expect($details)->toContain(['code' => '1210', 'credit' => '340']);

    expect($item->fresh()->ledger_entry_id)->toBe(5003);

    tenancy()->end();
    $tenant->delete();
});

test('finance:repost-ledger skips items without an existing ledger_entry_id', function () {
    $tenant = makeRepostTenant();
    tenancy()->initialize($tenant);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $order = makeRepostOrder($user);
    $item = makeRepostItem($order, ['ledger_entry_id' => null]);

    $mockService = Mockery::mock(LedgerPostingService::class)->makePartial();
    $mockService->shouldReceive('postReversalEntry')->never();
    $mockService->shouldReceive('post')->never();

    app()->instance(LedgerPostingService::class, $mockService);

    $this->artisan('finance:repost-ledger', [
        'identifier' => (string) $order->id,
        '--skip-initialize' => true,
    ])->assertSuccessful();

    expect($item->fresh()->ledger_entry_id)->toBeNull();

    tenancy()->end();
    $tenant->delete();
});

test('finance:repost-ledger --dry-run previews without making changes', function () {
    $tenant = makeRepostTenant();
    tenancy()->initialize($tenant);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $order = makeRepostOrder($user);
    $item = makeRepostItem($order, ['ledger_entry_id' => 6001]);

    $mockService = Mockery::mock(LedgerPostingService::class)->makePartial();
    $mockService->shouldReceive('postReversalEntry')->never();
    $mockService->shouldReceive('post')->never();

    app()->instance(LedgerPostingService::class, $mockService);

    $this->artisan('finance:repost-ledger', [
        'identifier' => (string) $order->id,
        '--dry-run' => true,
        '--skip-initialize' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('[DRY RUN]');

    // ledger_entry_id must remain unchanged
    expect($item->fresh()->ledger_entry_id)->toBe(6001);

    tenancy()->end();
    $tenant->delete();
});

test('finance:repost-ledger requires --tenant when run outside tenant context', function () {
    $this->artisan('finance:repost-ledger', [
        'identifier' => 'some-order-id',
    ])->assertFailed()
        ->expectsOutputToContain('--tenant');
});

test('finance:repost-ledger rolls back all item updates when re-posting fails', function () {
    $tenant = makeRepostTenant();
    tenancy()->initialize($tenant);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $order = makeRepostOrder($user);

    $itemA = makeRepostItem($order, ['ledger_entry_id' => 7001, 'provider_reference' => 'RPTA']);
    $itemB = makeRepostItem($order, ['ledger_entry_id' => 7002, 'provider_reference' => 'RPTB']);

    $callCount = 0;

    $mockService = Mockery::mock(LedgerPostingService::class)->makePartial();

    $mockService->shouldReceive('postReversalEntry')
        ->twice()
        ->andReturn(makeFakeEntry(9999));

    $mockService->shouldReceive('post')
        ->twice()
        ->andReturnUsing(function () use (&$callCount): JournalEntry {
            $callCount++;
            if ($callCount === 2) {
                throw new RuntimeException('Simulated ledger failure on second item.');
            }

            return makeFakeEntry(8001);
        });

    $mockService->shouldReceive('revenueAccount')->passthru();
    $mockService->shouldReceive('costAccount')->passthru();
    $mockService->shouldReceive('providerWalletAccount')->passthru();
    $mockService->shouldReceive('resolveJournal')->passthru();

    app()->instance(LedgerPostingService::class, $mockService);

    $this->artisan('finance:repost-ledger', [
        'identifier' => (string) $order->id,
        '--skip-initialize' => true,
    ])->assertFailed();

    // Both items must retain their original ledger_entry_id (transaction rolled back)
    expect($itemA->fresh()->ledger_entry_id)->toBe(7001)
        ->and($itemB->fresh()->ledger_entry_id)->toBe(7002);

    tenancy()->end();
    $tenant->delete();
});
