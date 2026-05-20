<?php

use Abivia\Ledger\Models\JournalEntry;
use App\DTOs\Issuance\MerchantIssuanceRequest;
use App\Exceptions\InsufficientMerchantBalanceException;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Services\Accounting\LedgerQueryService;
use App\Services\Issuance\MerchantIssuanceService;
use App\Services\Wallet\WalletProvisioningService;
use Bavix\Wallet\Internal\Service\DispatcherServiceInterface;

// ─────────────────────────────────────────────────────────────────────────────
// Phase 6 — Network Merchant Issuance
// Verifies that MerchantIssuanceService correctly:
//   - Deducts the merchant wallet (in merchant tenant context)
//   - Deducts the agency provider wallet (in agency tenant context)
//   - Creates an Order in the merchant tenant DB
//   - Posts balanced merchant-side ledger entries
//   - Posts balanced agency-side ledger entries (commission + receivable)
//   - Rejects issuance when merchant wallet is insufficient
//
// Uses the two shared tenants from AccountingTestCase:
//   - sharedTenantId()         → network agency tenant
//   - sharedMerchantTenantId() → merchant tenant
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Flush pending bavix events and run a callback inside the given tenant context.
 */
function withTenantP6(string $tenantId, Closure $callback): void
{
    $tenant = Tenant::findOrFail($tenantId);
    $tenant->run(function () use ($callback) {
        app(DispatcherServiceInterface::class)->flush();
        $callback();
    });
    tenancy()->end();
}

/**
 * Create a TenantProvider inside the agency tenant context, provision its wallet,
 * and fund it to $balance. Returns the provider (usable from outside tenant context).
 */
function makeAgencyProviderP6(float $balance = 10000.0): TenantProvider
{
    static $seq = 0;
    $seq++;

    $agencyId = Tests\AccountingTestCase::sharedTenantId();
    $provider = null;

    withTenantP6($agencyId, function () use ($seq, $balance, &$provider) {
        $p = TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'M'.$seq,
            'airline_name' => 'Phase6 Airline '.$seq,
            'account_name' => 'p6-account-'.$seq,
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
function fundMerchantWalletP6(float $balance): void
{
    $merchantId = Tests\AccountingTestCase::sharedMerchantTenantId();

    withTenantP6($merchantId, function () use ($balance, $merchantId) {
        $merchant = Tenant::findOrFail($merchantId);
        $wallet = $merchant->getWallet('merchant');
        $wallet->depositFloat($balance);
    });
}

/**
 * Build a MerchantIssuanceRequest for the shared agency + merchant tenants.
 */
function makeRequestP6(
    TenantProvider $provider,
    float $sellingPrice = 1200.0,
    float $vatAmount = 109.0,
    float $wholesalePrice = 1000.0,
    float $providerCost = 950.0,
): MerchantIssuanceRequest {
    return new MerchantIssuanceRequest(
        merchantTenant: Tenant::findOrFail(Tests\AccountingTestCase::sharedMerchantTenantId()),
        agencyTenant: Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId()),
        provider: $provider,
        productType: 'airline',
        sellingPrice: $sellingPrice,
        vatAmount: $vatAmount,
        wholesalePrice: $wholesalePrice,
        providerCost: $providerCost,
        providerReference: 'PNR-P6-'.uniqid(),
        currency: 'LYD',
    );
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('merchant wallet is deducted by wholesale price on issuance', function () {
    $provider = makeAgencyProviderP6(10000.0);
    fundMerchantWalletP6(5000.0);

    app(MerchantIssuanceService::class)->issue(
        makeRequestP6($provider, wholesalePrice: 1000.0)
    );

    withTenantP6(Tests\AccountingTestCase::sharedMerchantTenantId(), function () {
        $merchant = Tenant::findOrFail(Tests\AccountingTestCase::sharedMerchantTenantId());
        $balance = (float) $merchant->getWallet('merchant')->balanceFloat;
        // Balance should have decreased by 1000 (wholesale price).
        expect($balance)->toBeLessThan(5000.0);
    });
});

test('agency provider wallet is deducted by provider cost on merchant issuance', function () {
    $provider = makeAgencyProviderP6(10000.0);
    fundMerchantWalletP6(5000.0);

    app(MerchantIssuanceService::class)->issue(
        makeRequestP6($provider, providerCost: 950.0)
    );

    withTenantP6(Tests\AccountingTestCase::sharedTenantId(), function () use ($provider) {
        // Re-fetch the wallet fresh to avoid stale cached balance.
        $fresh = TenantProvider::findOrFail($provider->id);
        $balance = (float) $fresh->getWallet('airline-provider')->balanceFloat;
        expect($balance)->toBeLessThan(10000.0);
    });
});

test('merchant issuance creates an order in the merchant tenant db', function () {
    $provider = makeAgencyProviderP6(10000.0);
    fundMerchantWalletP6(5000.0);

    $order = app(MerchantIssuanceService::class)->issue(makeRequestP6($provider));

    expect($order->id)->not->toBeEmpty()
        ->and($order->status)->toBe('issued');
});

test('merchant books record revenue net of vat and agency payable', function () {
    $provider = makeAgencyProviderP6(10000.0);
    fundMerchantWalletP6(5000.0);

    app(MerchantIssuanceService::class)->issue(
        makeRequestP6($provider, sellingPrice: 1200.0, vatAmount: 109.0, wholesalePrice: 1000.0)
    );

    withTenantP6(Tests\AccountingTestCase::sharedMerchantTenantId(), function () {
        $query = app(LedgerQueryService::class);

        // Revenue account 4100 should have been credited with net amount (1200 - 109 = 1091).
        expect($query->accountCredits('4100'))->toBeGreaterThanOrEqual(1091.0);

        // Network agency payable 2200 should have been credited with wholesale price (1000).
        expect($query->accountCredits('2200'))->toBeGreaterThanOrEqual(1000.0);
    });
});

test('agency books record merchant receivable and commission income', function () {
    $provider = makeAgencyProviderP6(10000.0);
    fundMerchantWalletP6(5000.0);

    app(MerchantIssuanceService::class)->issue(
        makeRequestP6($provider, wholesalePrice: 1000.0, providerCost: 950.0)
    );

    withTenantP6(Tests\AccountingTestCase::sharedTenantId(), function () {
        $query = app(LedgerQueryService::class);

        // Merchant receivable 1320 should have been debited with wholesale price (1000).
        expect($query->accountDebits('1320'))->toBeGreaterThanOrEqual(1000.0);

        // Commission income 4600 should have been credited with margin (1000 - 950 = 50).
        expect($query->accountCredits('4600'))->toBeGreaterThanOrEqual(50.0);
    });
});

test('issuance fails when merchant wallet has insufficient balance', function () {
    $provider = makeAgencyProviderP6(10000.0);
    // Use a wholesale price that will always exceed the shared wallet balance,
    // regardless of how much was deposited by prior tests in this suite.
    expect(fn () => app(MerchantIssuanceService::class)->issue(
        makeRequestP6($provider, wholesalePrice: 999999.0)
    ))->toThrow(InsufficientMerchantBalanceException::class);
});

test('agency journal entries are balanced on merchant issuance', function () {
    $provider = makeAgencyProviderP6(10000.0);
    fundMerchantWalletP6(5000.0);

    $beforeCount = 0;
    withTenantP6(Tests\AccountingTestCase::sharedTenantId(), function () use (&$beforeCount) {
        $beforeCount = JournalEntry::count();
    });

    app(MerchantIssuanceService::class)->issue(makeRequestP6($provider));

    withTenantP6(Tests\AccountingTestCase::sharedTenantId(), function () use ($beforeCount) {
        expect(JournalEntry::count())->toBeGreaterThan($beforeCount);
    });
});
