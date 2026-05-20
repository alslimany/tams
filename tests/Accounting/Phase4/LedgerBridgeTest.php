<?php

use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\JournalEntry;
use Abivia\Ledger\Models\LedgerAccount;
use Abivia\Ledger\Models\SubJournal;
use App\DTOs\Accounting\IssuanceDTO;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Wallet\WalletProvisioningService;

// ─────────────────────────────────────────────────────────────────────────────
// Phase 4 — Ledger Bridge
// Verifies that LedgerPostingService correctly posts balanced double-entry
// journal entries, and that the wallet event listener auto-posts entries
// when wallet transactions carry ledger_accounts metadata.
//
// All tests share a single tenant (bootstrapped once in AccountingTestCase)
// to avoid the memory cost of running the full abivia CoA bootstrap 8 times.
// ─────────────────────────────────────────────────────────────────────────────

// ─── Helpers (must be called inside tenant context) ──────────────────────────

/**
 * Sum all debit amounts (stored as negative) across journal_entry_details for a given entry.
 */
function sumDebitsP4(JournalEntry $entry): float
{
    return abs((float) JournalDetail::where('journalEntryId', $entry->journalEntryId)
        ->where('amount', '<', 0)
        ->sum('amount'));
}

/**
 * Sum all credit amounts (stored as positive) across journal_entry_details.
 */
function sumCreditsP4(JournalEntry $entry): float
{
    return (float) JournalDetail::where('journalEntryId', $entry->journalEntryId)
        ->where('amount', '>', 0)
        ->sum('amount');
}

/**
 * Run a callback inside the shared Phase 4 tenant context.
 * Always ends tenancy afterward so the next test starts clean.
 */
function withSharedTenant(Closure $callback): void
{
    $tenant = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
    $tenant->run(function () use ($callback) {
        // Flush any pending bavix events from previous tests before running assertions.
        app(\Bavix\Wallet\Internal\Service\DispatcherServiceInterface::class)->flush();
        $callback();
    });
    tenancy()->end();
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('postIssuanceEntry posts a balanced journal entry for airline sale', function () {
    withSharedTenant(function () {
        $dto = new IssuanceDTO(
            orderId: 'ORD-001',
            productType: 'airline',
            sellingPrice: 1200.000,
            vatAmount: 109.000,
            providerCost: 950.000,
            providerReference: 'PNR123',
        );

        $entry = app(LedgerPostingService::class)->postIssuanceEntry($dto);

        expect($entry)->toBeInstanceOf(JournalEntry::class)
            ->and($entry->journalEntryId)->toBeInt();

        // Debits: 1200 (1110) + 950 (5100) = 2150
        // Credits: 1091 (4100) + 109 (2400) + 950 (1210) = 2150
        $debits = sumDebitsP4($entry);
        $credits = sumCreditsP4($entry);

        expect(round($debits, 3))->toBe(round($credits, 3));
    });
});

test('postIssuanceEntry posts to the correct sub-journal', function () {
    withSharedTenant(function () {
        $dto = new IssuanceDTO(
            orderId: 'ORD-002',
            productType: 'hotel',
            sellingPrice: 500.000,
            vatAmount: 45.000,
            providerCost: 400.000,
            providerReference: 'HTL-REF-001',
        );

        $entry = app(LedgerPostingService::class)->postIssuanceEntry($dto);

        $subJournal = SubJournal::where('subJournalUuid', $entry->subJournalUuid)->first();

        expect($subJournal)->not->toBeNull()
            ->and($subJournal->code)->toBe('HTL');
    });
});

test('postIssuanceEntry reference contains the order ID', function () {
    withSharedTenant(function () {
        $dto = new IssuanceDTO(
            orderId: 'ORD-999',
            productType: 'airline',
            sellingPrice: 1000.000,
            vatAmount: 91.000,
            providerCost: 800.000,
            providerReference: 'PNR999',
        );

        $entry = app(LedgerPostingService::class)->postIssuanceEntry($dto);

        $extra = json_decode($entry->extra, true);
        expect($extra['reference'])->toContain('ORD-999');
    });
});

test('postIssuanceEntry credits the correct revenue account for insurance', function () {
    withSharedTenant(function () {
        $dto = new IssuanceDTO(
            orderId: 'ORD-INS-01',
            productType: 'insurance',
            sellingPrice: 300.000,
            vatAmount: 27.000,
            providerCost: 200.000,
            providerReference: 'POL-001',
        );

        $entry = app(LedgerPostingService::class)->postIssuanceEntry($dto);

        $revenueUuid = LedgerAccount::where('code', '4300')->value('ledgerUuid');
        expect($revenueUuid)->not->toBeNull();

        $revenueDetail = JournalDetail::where('journalEntryId', $entry->journalEntryId)
            ->where('ledgerUuid', $revenueUuid)
            ->first();

        expect($revenueDetail)->not->toBeNull()
            ->and((float) $revenueDetail->amount)->toBeGreaterThan(0); // credits are stored positive
    });
});

test('wallet withdrawal with ledger meta auto-posts journal entry via event listener', function () {
    withSharedTenant(function () {
        $provider = TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'TS',
            'airline_name' => 'Test Airline',
            'account_name' => 'test-account',
            'credentials' => '{}',
            'is_active' => true,
        ]);

        app(WalletProvisioningService::class)->provisionProviderWallet($provider);
        $wallet = $provider->getWallet('airline-provider');
        $wallet->depositFloat(5000.000);

        $beforeCount = JournalEntry::count();
        $countAfterDeposit = $beforeCount; // alias for clarity

        // Withdraw with ledger_accounts meta — listener should auto-post
        $wallet->withdrawFloat(950.000, [
            'order_id' => 'ORD-AUTO-01',
            'order_type' => 'airline',
            'tx_type' => 'issuance',
            'ledger_accounts' => ['debit' => '5100', 'credit' => '1210'],
            'reference' => 'PNR-AUTO',
        ]);

        $afterCount = JournalEntry::count();
        expect($afterCount - $countAfterDeposit)->toBe(1, 'Expected exactly 1 new journal entry after withdrawFloat, got '.($afterCount - $countAfterDeposit));
    });
});

test('wallet transaction without ledger meta does not create a journal entry', function () {
    withSharedTenant(function () {
        $tenant = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        $wallet = $tenant->getWallet('operating');

        $beforeCount = JournalEntry::count();

        // Deposit with no ledger_accounts meta
        $wallet->depositFloat(1000.000, ['tx_type' => 'deposit']);

        // Force flush to ensure any queued events are processed before asserting.
        app(\Bavix\Wallet\Internal\Service\DispatcherServiceInterface::class)->flush();

        $afterCount = JournalEntry::count();
        expect($afterCount)->toBe($beforeCount);
    });
});

test('postReversalEntry produces a balanced entry that negates the original', function () {
    withSharedTenant(function () {
        $service = app(LedgerPostingService::class);

        $dto = new IssuanceDTO(
            orderId: 'ORD-REV-01',
            productType: 'airline',
            sellingPrice: 1200.000,
            vatAmount: 109.000,
            providerCost: 950.000,
            providerReference: 'PNR-REV',
        );
        $service->postIssuanceEntry($dto);

        $reversal = $service->postReversalEntry(
            originalOrderId: 'ORD-REV-01',
            sellingPrice: 1200.000,
            productType: 'airline',
            vatAmount: 109.000,
            providerCost: 950.000,
        );

        expect($reversal)->toBeInstanceOf(JournalEntry::class);

        $debits = sumDebitsP4($reversal);
        $credits = sumCreditsP4($reversal);

        expect(round($debits, 3))->toBe(round($credits, 3));
    });
});

test('postReversalEntry with cancellation fee posts fee to account 4700', function () {
    withSharedTenant(function () {
        $reversal = app(LedgerPostingService::class)->postReversalEntry(
            originalOrderId: 'ORD-FEE-01',
            sellingPrice: 1000.000,
            productType: 'airline',
            vatAmount: 91.000,
            providerCost: 800.000,
            cancellationFee: 50.000,
        );

        $feeUuid = LedgerAccount::where('code', '4700')->value('ledgerUuid');
        expect($feeUuid)->not->toBeNull();

        $feeLine = JournalDetail::where('journalEntryId', $reversal->journalEntryId)
            ->where('ledgerUuid', $feeUuid)
            ->first();

        expect($feeLine)->not->toBeNull()
            ->and((float) $feeLine->amount)->toBeGreaterThan(0); // credit to fee income stored positive
    });
});
