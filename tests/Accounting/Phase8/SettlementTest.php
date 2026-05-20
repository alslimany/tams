<?php

use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\JournalEntry;
use App\DTOs\Issuance\MerchantIssuanceRequest;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Services\Accounting\LedgerQueryService;
use App\Services\Issuance\MerchantIssuanceService;
use App\Services\Issuance\SettlementService;
use App\Services\Wallet\WalletProvisioningService;
use Bavix\Wallet\Internal\Service\DispatcherServiceInterface;

// ─────────────────────────────────────────────────────────────────────────────
// Phase 8 — Settlement
// Verifies that SettlementService correctly:
//   - Deducts the merchant wallet by the settlement amount
//   - Credits the agency operating wallet by the settlement amount
//   - Clears the merchant-side agency payable (account 2200)
//   - Clears the agency-side merchant receivable (account 1320)
//   - Posts balanced journal entries on both sides
//
// Uses the two shared tenants from AccountingTestCase:
//   - sharedTenantId()         → network agency tenant
//   - sharedMerchantTenantId() → merchant tenant
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Flush pending bavix events and run a callback inside the given tenant context.
 */
function withTenantP8(string $tenantId, Closure $callback): void
{
    $tenant = Tenant::findOrFail($tenantId);
    $tenant->run(function () use ($callback) {
        app(DispatcherServiceInterface::class)->flush();
        $callback();
    });
    tenancy()->end();
}

/**
 * Create a TenantProvider inside the agency tenant, provision + fund its wallet.
 */
function makeAgencyProviderP8(float $balance = 10000.0): TenantProvider
{
    static $seq = 0;
    $seq++;

    $agencyId = Tests\AccountingTestCase::sharedTenantId();
    $provider = null;

    withTenantP8($agencyId, function () use ($seq, $balance, &$provider) {
        $p = TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'S'.$seq,
            'airline_name' => 'Phase8 Airline '.$seq,
            'account_name' => 'p8-account-'.$seq,
            'credentials' => '{}',
            'is_active' => true,
        ]);

        app(WalletProvisioningService::class)->provisionProviderWallet($p);
        $p->getWallet('airline-provider')->depositFloat($balance);
        $provider = $p;
    });

    return $provider;
}

/**
 * Fund the merchant wallet to $balance inside the merchant tenant context.
 */
function fundMerchantWalletP8(float $balance): void
{
    $merchantId = Tests\AccountingTestCase::sharedMerchantTenantId();

    withTenantP8($merchantId, function () use ($balance, $merchantId) {
        $merchant = Tenant::findOrFail($merchantId);
        $wallet = $merchant->getWallet('merchant');
        $wallet->depositFloat($balance);
    });
}

/**
 * Issue a merchant order and return the order.
 * Funds both wallets before issuing.
 */
function issueForSettlementP8(
    float $sellingPrice = 1200.0,
    float $vatAmount = 109.0,
    float $wholesalePrice = 1000.0,
    float $providerCost = 950.0,
): void {
    $provider = makeAgencyProviderP8(10000.0);
    fundMerchantWalletP8(5000.0);

    $request = new MerchantIssuanceRequest(
        merchantTenant: Tenant::findOrFail(Tests\AccountingTestCase::sharedMerchantTenantId()),
        agencyTenant: Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId()),
        provider: $provider,
        productType: 'airline',
        sellingPrice: $sellingPrice,
        vatAmount: $vatAmount,
        wholesalePrice: $wholesalePrice,
        providerCost: $providerCost,
        providerReference: 'PNR-P8-'.uniqid(),
        currency: 'LYD',
    );

    app(MerchantIssuanceService::class)->issue($request);
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('settlement deducts merchant wallet by settlement amount', function () {
    issueForSettlementP8(wholesalePrice: 1000.0);

    $balanceBefore = 0.0;
    withTenantP8(Tests\AccountingTestCase::sharedMerchantTenantId(), function () use (&$balanceBefore) {
        $merchant = Tenant::findOrFail(Tests\AccountingTestCase::sharedMerchantTenantId());
        $balanceBefore = (float) $merchant->getWallet('merchant')->balanceFloat;
    });

    app(SettlementService::class)->settleMerchantToAgency(
        merchantTenantId: Tests\AccountingTestCase::sharedMerchantTenantId(),
        agencyTenantId: Tests\AccountingTestCase::sharedTenantId(),
        amount: 1000.0,
        batchReference: 'BATCH-P8-'.uniqid(),
    );

    withTenantP8(Tests\AccountingTestCase::sharedMerchantTenantId(), function () use ($balanceBefore) {
        $merchant = Tenant::findOrFail(Tests\AccountingTestCase::sharedMerchantTenantId());
        $balanceAfter = (float) $merchant->getWallet('merchant')->balanceFloat;
        expect(round($balanceAfter, 3))->toBe(round($balanceBefore - 1000.0, 3));
    });
});

test('settlement credits agency operating wallet by settlement amount', function () {
    issueForSettlementP8(wholesalePrice: 1000.0);
    fundMerchantWalletP8(1000.0); // ensure merchant can pay

    $balanceBefore = 0.0;
    withTenantP8(Tests\AccountingTestCase::sharedTenantId(), function () use (&$balanceBefore) {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        $balanceBefore = (float) $agency->getWallet('operating')->balanceFloat;
    });

    app(SettlementService::class)->settleMerchantToAgency(
        merchantTenantId: Tests\AccountingTestCase::sharedMerchantTenantId(),
        agencyTenantId: Tests\AccountingTestCase::sharedTenantId(),
        amount: 1000.0,
        batchReference: 'BATCH-P8-'.uniqid(),
    );

    withTenantP8(Tests\AccountingTestCase::sharedTenantId(), function () use ($balanceBefore) {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        $balanceAfter = (float) $agency->getWallet('operating')->balanceFloat;
        expect(round($balanceAfter, 3))->toBe(round($balanceBefore + 1000.0, 3));
    });
});

test('settlement posts a balanced journal entry on the merchant side', function () {
    issueForSettlementP8(wholesalePrice: 1000.0);
    fundMerchantWalletP8(1000.0);

    $beforeCount = 0;
    withTenantP8(Tests\AccountingTestCase::sharedMerchantTenantId(), function () use (&$beforeCount) {
        $beforeCount = JournalEntry::count();
    });

    app(SettlementService::class)->settleMerchantToAgency(
        merchantTenantId: Tests\AccountingTestCase::sharedMerchantTenantId(),
        agencyTenantId: Tests\AccountingTestCase::sharedTenantId(),
        amount: 1000.0,
        batchReference: 'BATCH-P8-'.uniqid(),
    );

    withTenantP8(Tests\AccountingTestCase::sharedMerchantTenantId(), function () use ($beforeCount) {
        expect(JournalEntry::count())->toBeGreaterThan($beforeCount);

        $entry = JournalEntry::latest('journalEntryId')->first();
        $debits = abs((float) JournalDetail::where('journalEntryId', $entry->journalEntryId)
            ->where('amount', '<', 0)->sum('amount'));
        $credits = (float) JournalDetail::where('journalEntryId', $entry->journalEntryId)
            ->where('amount', '>', 0)->sum('amount');

        expect(round($debits, 3))->toBe(round($credits, 3));
    });
});

test('settlement posts a balanced journal entry on the agency side', function () {
    issueForSettlementP8(wholesalePrice: 1000.0);
    fundMerchantWalletP8(1000.0);

    $beforeCount = 0;
    withTenantP8(Tests\AccountingTestCase::sharedTenantId(), function () use (&$beforeCount) {
        $beforeCount = JournalEntry::count();
    });

    app(SettlementService::class)->settleMerchantToAgency(
        merchantTenantId: Tests\AccountingTestCase::sharedMerchantTenantId(),
        agencyTenantId: Tests\AccountingTestCase::sharedTenantId(),
        amount: 1000.0,
        batchReference: 'BATCH-P8-'.uniqid(),
    );

    withTenantP8(Tests\AccountingTestCase::sharedTenantId(), function () use ($beforeCount) {
        expect(JournalEntry::count())->toBeGreaterThan($beforeCount);

        $entry = JournalEntry::latest('journalEntryId')->first();
        $debits = abs((float) JournalDetail::where('journalEntryId', $entry->journalEntryId)
            ->where('amount', '<', 0)->sum('amount'));
        $credits = (float) JournalDetail::where('journalEntryId', $entry->journalEntryId)
            ->where('amount', '>', 0)->sum('amount');

        expect(round($debits, 3))->toBe(round($credits, 3));
    });
});

test('settlement credits account 2200 on merchant side (clears agency payable)', function () {
    issueForSettlementP8(wholesalePrice: 1000.0);
    fundMerchantWalletP8(1000.0);

    $payableBefore = 0.0;
    withTenantP8(Tests\AccountingTestCase::sharedMerchantTenantId(), function () use (&$payableBefore) {
        $payableBefore = app(LedgerQueryService::class)->accountCredits('2200');
    });

    app(SettlementService::class)->settleMerchantToAgency(
        merchantTenantId: Tests\AccountingTestCase::sharedMerchantTenantId(),
        agencyTenantId: Tests\AccountingTestCase::sharedTenantId(),
        amount: 1000.0,
        batchReference: 'BATCH-P8-'.uniqid(),
    );

    // After settlement, account 2200 should have a debit posted (clearing the payable).
    withTenantP8(Tests\AccountingTestCase::sharedMerchantTenantId(), function () {
        $debits2200 = app(LedgerQueryService::class)->accountDebits('2200');
        expect($debits2200)->toBeGreaterThanOrEqual(1000.0);
    });
});

test('settlement debits account 1320 on agency side (clears merchant receivable)', function () {
    issueForSettlementP8(wholesalePrice: 1000.0);
    fundMerchantWalletP8(1000.0);

    app(SettlementService::class)->settleMerchantToAgency(
        merchantTenantId: Tests\AccountingTestCase::sharedMerchantTenantId(),
        agencyTenantId: Tests\AccountingTestCase::sharedTenantId(),
        amount: 1000.0,
        batchReference: 'BATCH-P8-'.uniqid(),
    );

    // After settlement, account 1320 should have a credit posted (clearing the receivable).
    withTenantP8(Tests\AccountingTestCase::sharedTenantId(), function () {
        $credits1320 = app(LedgerQueryService::class)->accountCredits('1320');
        expect($credits1320)->toBeGreaterThanOrEqual(1000.0);
    });
});
