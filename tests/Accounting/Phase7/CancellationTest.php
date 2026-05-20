<?php

use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\JournalEntry;
use Abivia\Ledger\Models\LedgerAccount;
use App\DTOs\Issuance\DirectIssuanceRequest;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Services\Issuance\CancellationService;
use App\Services\Issuance\DirectAgencyIssuanceService;
use App\Services\Wallet\WalletProvisioningService;
use Bavix\Wallet\Internal\Service\DispatcherServiceInterface;

// ─────────────────────────────────────────────────────────────────────────────
// Phase 7 — Cancellations, Voids & Refunds
// Verifies that CancellationService correctly:
//   - Restores the provider wallet balance
//   - Updates the order status to 'cancelled'
//   - Records the correct refunded amount (net of any cancellation fee)
//   - Posts a balanced reversal journal entry
//   - Posts cancellation fee income to account 4700 when a fee applies
//
// All tests share the single tenant bootstrapped in AccountingTestCase.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Run a callback inside the shared Phase 7 tenant context.
 */
function withSharedTenantP7(Closure $callback): void
{
    $tenant = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
    $tenant->run(function () use ($callback) {
        app(DispatcherServiceInterface::class)->flush();
        $callback();
    });
    tenancy()->end();
}

/**
 * Create a TenantProvider with a provisioned airline-provider wallet funded to $balance.
 * Must be called inside a tenant context.
 */
function makeProviderP7(float $balance = 10000.0): TenantProvider
{
    static $seq = 0;
    $seq++;

    $provider = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'Q'.$seq,
        'airline_name' => 'Phase7 Airline '.$seq,
        'account_name' => 'p7-account-'.$seq,
        'credentials' => '{}',
        'is_active' => true,
    ]);

    app(WalletProvisioningService::class)->provisionProviderWallet($provider);
    $provider->getWallet('airline-provider')->depositFloat($balance);

    return $provider;
}

/**
 * Issue a standard airline order and return [order, provider].
 * Must be called inside a tenant context.
 */
function issueOrderP7(
    Tenant $agency,
    float $sellingPrice = 1200.0,
    float $vatAmount = 109.0,
    float $providerCost = 950.0,
    float $providerBalance = 10000.0,
): array {
    $provider = makeProviderP7($providerBalance);

    $request = new DirectIssuanceRequest(
        agency: $agency,
        provider: $provider,
        productType: 'airline',
        sellingPrice: $sellingPrice,
        vatAmount: $vatAmount,
        providerCost: $providerCost,
        providerReference: 'PNR-P7-'.uniqid(),
        currency: 'LYD',
    );

    $order = app(DirectAgencyIssuanceService::class)->issue($request);

    return [$order, $provider];
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('cancellation restores provider wallet balance', function () {
    withSharedTenantP7(function () {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        [$order, $provider] = issueOrderP7($agency, sellingPrice: 1200.0, providerCost: 950.0, providerBalance: 10000.0);

        $balanceAfterIssuance = (float) $provider->getWallet('airline-provider')->balanceFloat;
        expect(round($balanceAfterIssuance, 3))->toBe(round(10000.0 - 950.0, 3));

        app(CancellationService::class)->cancel(
            order: $order,
            productType: 'airline',
            sellingPrice: 1200.0,
            vatAmount: 109.0,
            providerCost: 950.0,
            provider: $provider,
        );

        $balanceAfterCancellation = (float) $provider->fresh()->getWallet('airline-provider')->balanceFloat;
        expect(round($balanceAfterCancellation, 3))->toBe(10000.0);
    });
});

test('cancellation updates order status to cancelled', function () {
    withSharedTenantP7(function () {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        [$order, $provider] = issueOrderP7($agency);

        expect($order->status)->toBe('issued');

        $cancelled = app(CancellationService::class)->cancel(
            order: $order,
            productType: 'airline',
            sellingPrice: 1200.0,
            vatAmount: 109.0,
            providerCost: 950.0,
            provider: $provider,
        );

        expect($cancelled->status)->toBe('cancelled')
            ->and((float) $cancelled->amount_refunded)->toBe(1200.0);
    });
});

test('cancellation posts a balanced reversal journal entry', function () {
    withSharedTenantP7(function () {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        [$order, $provider] = issueOrderP7($agency);

        $beforeCount = JournalEntry::count();

        app(CancellationService::class)->cancel(
            order: $order,
            productType: 'airline',
            sellingPrice: 1200.0,
            vatAmount: 109.0,
            providerCost: 950.0,
            provider: $provider,
        );

        expect(JournalEntry::count())->toBeGreaterThan($beforeCount);

        $entry = JournalEntry::latest('journalEntryId')->first();
        $debits = abs((float) JournalDetail::where('journalEntryId', $entry->journalEntryId)
            ->where('amount', '<', 0)->sum('amount'));
        $credits = (float) JournalDetail::where('journalEntryId', $entry->journalEntryId)
            ->where('amount', '>', 0)->sum('amount');

        expect(round($debits, 3))->toBe(round($credits, 3));
    });
});

test('cancellation with fee retains fee in account 4700', function () {
    withSharedTenantP7(function () {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        [$order, $provider] = issueOrderP7($agency, sellingPrice: 1200.0, vatAmount: 109.0, providerCost: 950.0);

        $cancellationFee = 50.0;

        $cancelled = app(CancellationService::class)->cancel(
            order: $order,
            productType: 'airline',
            sellingPrice: 1200.0,
            vatAmount: 109.0,
            providerCost: 950.0,
            provider: $provider,
            cancellationFee: $cancellationFee,
        );

        // Refund = sellingPrice - fee
        expect((float) $cancelled->amount_refunded)->toBe(1200.0 - $cancellationFee);

        // Account 4700 (cancellation fee income) must have been credited with the fee.
        $entry = JournalEntry::latest('journalEntryId')->first();
        $account4700 = LedgerAccount::where('code', '4700')->first();
        $feeCredit = (float) JournalDetail::where('journalEntryId', $entry->journalEntryId)
            ->where('ledgerUuid', $account4700->ledgerUuid)
            ->where('amount', '>', 0)
            ->sum('amount');

        expect(round($feeCredit, 3))->toBe($cancellationFee);
    });
});

test('cancellation with fee posts a balanced entry', function () {
    withSharedTenantP7(function () {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        [$order, $provider] = issueOrderP7($agency, sellingPrice: 1200.0, vatAmount: 109.0, providerCost: 950.0);

        app(CancellationService::class)->cancel(
            order: $order,
            productType: 'airline',
            sellingPrice: 1200.0,
            vatAmount: 109.0,
            providerCost: 950.0,
            provider: $provider,
            cancellationFee: 50.0,
        );

        $entry = JournalEntry::latest('journalEntryId')->first();
        $debits = abs((float) JournalDetail::where('journalEntryId', $entry->journalEntryId)
            ->where('amount', '<', 0)->sum('amount'));
        $credits = (float) JournalDetail::where('journalEntryId', $entry->journalEntryId)
            ->where('amount', '>', 0)->sum('amount');

        expect(round($debits, 3))->toBe(round($credits, 3));
    });
});
