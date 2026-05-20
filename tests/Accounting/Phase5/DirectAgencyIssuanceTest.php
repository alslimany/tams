<?php

use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\JournalEntry;
use App\DTOs\Issuance\DirectIssuanceRequest;
use App\Exceptions\InsufficientProviderBalanceException;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Services\Accounting\LedgerQueryService;
use App\Services\Issuance\DirectAgencyIssuanceService;
use App\Services\Wallet\WalletProvisioningService;
use Bavix\Wallet\Internal\Service\DispatcherServiceInterface;

// ─────────────────────────────────────────────────────────────────────────────
// Phase 5 — Direct Agency Issuance Flow
// Verifies that DirectAgencyIssuanceService correctly:
//   - Deducts the provider wallet
//   - Creates an Order record
//   - Posts a balanced multi-line journal entry
//   - Rejects issuance when provider wallet is insufficient
//   - Records revenue net of VAT and VAT payable separately
//
// All tests share the single tenant bootstrapped in AccountingTestCase.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Run a callback inside the shared Phase 5 tenant context.
 * Flushes pending bavix events before the callback and ends tenancy after.
 */
function withSharedTenantP5(Closure $callback): void
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
function makeProviderP5(float $balance = 10000.0): TenantProvider
{
    static $seq = 0;
    $seq++;

    $provider = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'P'.$seq,
        'airline_name' => 'Phase5 Airline '.$seq,
        'account_name' => 'p5-account-'.$seq,
        'credentials' => '{}',
        'is_active' => true,
    ]);

    app(WalletProvisioningService::class)->provisionProviderWallet($provider);
    $provider->getWallet('airline-provider')->depositFloat($balance);

    return $provider;
}

/**
 * Build a standard DirectIssuanceRequest for the given agency and provider.
 */
function makeRequestP5(
    Tenant $agency,
    TenantProvider $provider,
    float $sellingPrice = 1200.0,
    float $vatAmount = 109.0,
    float $providerCost = 950.0,
): DirectIssuanceRequest {
    return new DirectIssuanceRequest(
        agency: $agency,
        provider: $provider,
        productType: 'airline',
        sellingPrice: $sellingPrice,
        vatAmount: $vatAmount,
        providerCost: $providerCost,
        providerReference: 'PNR-P5-'.uniqid(),
        currency: 'LYD',
    );
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('successful airline issuance deducts provider wallet', function () {
    withSharedTenantP5(function () {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        $provider = makeProviderP5(10000.0);

        $request = makeRequestP5($agency, $provider, sellingPrice: 1200.0, providerCost: 950.0);
        app(DirectAgencyIssuanceService::class)->issue($request);

        $balance = (float) $provider->getWallet('airline-provider')->balanceFloat;
        expect(round($balance, 3))->toBe(round(10000.0 - 950.0, 3));
    });
});

test('successful issuance creates an order record', function () {
    withSharedTenantP5(function () {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        $provider = makeProviderP5(10000.0);

        $order = app(DirectAgencyIssuanceService::class)->issue(
            makeRequestP5($agency, $provider)
        );

        expect($order->id)->not->toBeEmpty()
            ->and($order->status)->toBe('issued')
            ->and((float) $order->grand_total)->toBe(1200.0);
    });
});

test('successful issuance posts a balanced journal entry', function () {
    withSharedTenantP5(function () {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        $provider = makeProviderP5(10000.0);

        $beforeCount = JournalEntry::count();

        app(DirectAgencyIssuanceService::class)->issue(
            makeRequestP5($agency, $provider)
        );

        // At least one new journal entry must have been posted.
        expect(JournalEntry::count())->toBeGreaterThan($beforeCount);

        // The most recent entry must be balanced (debits == credits).
        $entry = JournalEntry::latest('journalEntryId')->first();
        $debits = abs((float) JournalDetail::where('journalEntryId', $entry->journalEntryId)
            ->where('amount', '<', 0)->sum('amount'));
        $credits = (float) JournalDetail::where('journalEntryId', $entry->journalEntryId)
            ->where('amount', '>', 0)->sum('amount');

        expect(round($debits, 3))->toBe(round($credits, 3));
    });
});

test('issuance fails when provider wallet has insufficient balance', function () {
    withSharedTenantP5(function () {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        $provider = makeProviderP5(500.0); // only 500, but cost is 950

        expect(fn () => app(DirectAgencyIssuanceService::class)->issue(
            makeRequestP5($agency, $provider, providerCost: 950.0)
        ))->toThrow(InsufficientProviderBalanceException::class);

        // Wallet balance must be unchanged.
        $balance = (float) $provider->getWallet('airline-provider')->balanceFloat;
        expect(round($balance, 3))->toBe(500.0);
    });
});

test('issuance revenue is recorded net of VAT and VAT payable is separate', function () {
    withSharedTenantP5(function () {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        $provider = makeProviderP5(10000.0);

        $sellingPrice = 1200.0;
        $vatAmount = 109.0;
        $revenueNet = $sellingPrice - $vatAmount; // 1091

        app(DirectAgencyIssuanceService::class)->issue(
            makeRequestP5($agency, $provider, sellingPrice: $sellingPrice, vatAmount: $vatAmount)
        );

        $query = app(LedgerQueryService::class);

        // Revenue account 4100 should have been credited with the net amount.
        expect($query->accountCredits('4100'))->toBeGreaterThanOrEqual($revenueNet);

        // VAT payable account 2400 should have been credited with the VAT amount.
        expect($query->accountCredits('2400'))->toBeGreaterThanOrEqual($vatAmount);
    });
});

test('provider wallet balance is unchanged when issuance throws before deduction', function () {
    withSharedTenantP5(function () {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        $provider = makeProviderP5(10000.0);

        // Trigger the balance check failure (cost > balance).
        try {
            app(DirectAgencyIssuanceService::class)->issue(
                makeRequestP5($agency, $provider, providerCost: 99999.0)
            );
        } catch (InsufficientProviderBalanceException) {
            // Expected.
        }

        $balance = (float) $provider->getWallet('airline-provider')->balanceFloat;
        expect(round($balance, 3))->toBe(10000.0);
    });
});
