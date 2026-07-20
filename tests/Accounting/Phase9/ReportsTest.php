<?php

use App\DTOs\Issuance\DirectIssuanceRequest;
use App\DTOs\Issuance\MerchantIssuanceRequest;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Services\Accounting\Reports\CancellationVoidAuditReport;
use App\Services\Accounting\Reports\GrossMarginReport;
use App\Services\Accounting\Reports\MerchantSettlementAgingReport;
use App\Services\Accounting\Reports\RevenueByProductReport;
use App\Services\Accounting\Reports\TrialBalanceReport;
use App\Services\Accounting\Reports\VATSummaryReport;
use App\Services\Issuance\CancellationService;
use App\Services\Issuance\DirectAgencyIssuanceService;
use App\Services\Issuance\MerchantIssuanceService;
use App\Services\Issuance\SettlementService;
use App\Services\Wallet\WalletProvisioningService;
use Bavix\Wallet\Internal\Service\DispatcherServiceInterface;

// ─────────────────────────────────────────────────────────────────────────────
// Phase 9 — Reports & Reconciliation
// Verifies that all report services return correct data after issuance,
// cancellation, and settlement operations.
//
// Uses the two shared tenants from AccountingTestCase.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Flush pending bavix events and run a callback inside the given tenant context.
 */
function withTenantP9(string $tenantId, Closure $callback): void
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
function makeAgencyProviderP9(float $balance = 10000.0): TenantProvider
{
    static $seq = 0;
    $seq++;

    $agencyId = Tests\AccountingTestCase::sharedTenantId();
    $provider = null;

    withTenantP9($agencyId, function () use ($seq, $balance, &$provider) {
        $p = TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'T'.$seq,
            'airline_name' => 'Phase9 Airline '.$seq,
            'account_name' => 'p9-account-'.$seq,
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
 * Issue a direct agency airline order inside the agency tenant context.
 * Returns [order, provider].
 */
function issueDirectP9(
    float $sellingPrice = 1200.0,
    float $vatAmount = 109.0,
    float $providerCost = 950.0,
): array {
    $agencyId = Tests\AccountingTestCase::sharedTenantId();
    $order = null;
    $provider = null;

    withTenantP9($agencyId, function () use ($agencyId, $sellingPrice, $vatAmount, $providerCost, &$order, &$provider) {
        $agency = Tenant::findOrFail($agencyId);
        $p = TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'U'.uniqid(),
            'airline_name' => 'P9 Direct Airline',
            'account_name' => 'p9d-'.uniqid(),
            'credentials' => '{}',
            'is_active' => true,
        ]);
        app(WalletProvisioningService::class)->provisionProviderWallet($p);
        $p->getWallet('airline-provider')->depositFloat(10000.0);

        $request = new DirectIssuanceRequest(
            agency: $agency,
            provider: $p,
            productType: 'airline',
            sellingPrice: $sellingPrice,
            vatAmount: $vatAmount,
            providerCost: $providerCost,
            providerReference: 'PNR-P9-'.uniqid(),
            currency: 'LYD',
        );

        $order = app(DirectAgencyIssuanceService::class)->issue($request);
        $provider = $p;
    });

    return [$order, $provider];
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('revenue report shows correct airline revenue after issuance', function () {
    withTenantP9(Tests\AccountingTestCase::sharedTenantId(), function () {
        $revenueBefore = app(RevenueByProductReport::class)->generate()['airline'];

        // Issue with sellingPrice=1200, vatAmount=109 → net revenue = 1091
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        $p = TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'V'.uniqid(),
            'airline_name' => 'P9 Rev Airline',
            'account_name' => 'p9r-'.uniqid(),
            'credentials' => '{}',
            'is_active' => true,
        ]);
        app(WalletProvisioningService::class)->provisionProviderWallet($p);
        $p->getWallet('airline-provider')->depositFloat(10000.0);

        app(DirectAgencyIssuanceService::class)->issue(new DirectIssuanceRequest(
            agency: $agency,
            provider: $p,
            productType: 'airline',
            sellingPrice: 1200.0,
            vatAmount: 109.0,
            providerCost: 950.0,
            providerReference: 'PNR-P9R-'.uniqid(),
            currency: 'LYD',
        ));

        $revenueAfter = app(RevenueByProductReport::class)->generate()['airline'];
        expect(round($revenueAfter - $revenueBefore, 3))->toBe(1091.0); // 1200 - 109
    });
});

test('gross margin report shows correct profit after issuance', function () {
    withTenantP9(Tests\AccountingTestCase::sharedTenantId(), function () {
        $marginBefore = app(GrossMarginReport::class)->generate()['airline'];

        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        $p = TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'W'.uniqid(),
            'airline_name' => 'P9 Margin Airline',
            'account_name' => 'p9m-'.uniqid(),
            'credentials' => '{}',
            'is_active' => true,
        ]);
        app(WalletProvisioningService::class)->provisionProviderWallet($p);
        $p->getWallet('airline-provider')->depositFloat(10000.0);

        // sellingPrice=1200, vat=109, providerCost=950
        // revenue net = 1091, cost = 950, gross profit = 141
        app(DirectAgencyIssuanceService::class)->issue(new DirectIssuanceRequest(
            agency: $agency,
            provider: $p,
            productType: 'airline',
            sellingPrice: 1200.0,
            vatAmount: 109.0,
            providerCost: 950.0,
            providerReference: 'PNR-P9M-'.uniqid(),
            currency: 'LYD',
        ));

        $marginAfter = app(GrossMarginReport::class)->generate()['airline'];
        $profitDelta = round($marginAfter['gross_profit'] - $marginBefore['gross_profit'], 3);
        expect($profitDelta)->toBe(141.0); // 1091 - 950
    });
});

test('vat summary report shows correct collected vat after issuance', function () {
    withTenantP9(Tests\AccountingTestCase::sharedTenantId(), function () {
        $vatBefore = app(VATSummaryReport::class)->generate()['collected'];

        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        $p = TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'X'.uniqid(),
            'airline_name' => 'P9 VAT Airline',
            'account_name' => 'p9v-'.uniqid(),
            'credentials' => '{}',
            'is_active' => true,
        ]);
        app(WalletProvisioningService::class)->provisionProviderWallet($p);
        $p->getWallet('airline-provider')->depositFloat(10000.0);

        app(DirectAgencyIssuanceService::class)->issue(new DirectIssuanceRequest(
            agency: $agency,
            provider: $p,
            productType: 'airline',
            sellingPrice: 1200.0,
            vatAmount: 109.0,
            providerCost: 950.0,
            providerReference: 'PNR-P9V-'.uniqid(),
            currency: 'LYD',
        ));

        $vatAfter = app(VATSummaryReport::class)->generate()['collected'];
        expect(round($vatAfter - $vatBefore, 3))->toBe(109.0);
    });
});

test('trial balance is balanced after issuance', function () {
    withTenantP9(Tests\AccountingTestCase::sharedTenantId(), function () {
        $agency = Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId());
        $p = TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'Y'.uniqid(),
            'airline_name' => 'P9 TB Airline',
            'account_name' => 'p9tb-'.uniqid(),
            'credentials' => '{}',
            'is_active' => true,
        ]);
        app(WalletProvisioningService::class)->provisionProviderWallet($p);
        $p->getWallet('airline-provider')->depositFloat(10000.0);

        app(DirectAgencyIssuanceService::class)->issue(new DirectIssuanceRequest(
            agency: $agency,
            provider: $p,
            productType: 'airline',
            sellingPrice: 1200.0,
            vatAmount: 109.0,
            providerCost: 950.0,
            providerReference: 'PNR-P9TB-'.uniqid(),
            currency: 'LYD',
        ));

        $trialBalance = app(TrialBalanceReport::class)->generate(now()->addYear()->toDateString());
        $totalDebits = $trialBalance->sum('debit');
        $totalCredits = $trialBalance->sum('credit');

        expect(round($totalDebits, 3))->toBe(round($totalCredits, 3));
    });
});

test('cancellation audit report lists cancelled orders', function () {
    [$order, $provider] = issueDirectP9(sellingPrice: 1200.0, vatAmount: 109.0, providerCost: 950.0);

    withTenantP9(Tests\AccountingTestCase::sharedTenantId(), function () use ($order, $provider) {
        $auditBefore = app(CancellationVoidAuditReport::class)->generate()->count();

        app(CancellationService::class)->cancel(
            order: $order,
            productType: 'airline',
            sellingPrice: 1200.0,
            vatAmount: 109.0,
            providerCost: 950.0,
            provider: $provider,
        );

        $auditAfter = app(CancellationVoidAuditReport::class)->generate();
        expect($auditAfter->count())->toBe($auditBefore + 1);

        $row = $auditAfter->firstWhere('order_id', $order->id);
        expect($row)->not->toBeNull()
            ->and($row['grand_total'])->toBe(1200.0)
            ->and($row['amount_refunded'])->toBe(1200.0)
            ->and($row['cancellation_fee'])->toBe(0.0);
    });
});

test('merchant settlement aging report shows outstanding after issuance', function () {
    $provider = makeAgencyProviderP9(10000.0);

    withTenantP9(Tests\AccountingTestCase::sharedMerchantTenantId(), function () {
        $merchant = Tenant::findOrFail(Tests\AccountingTestCase::sharedMerchantTenantId());
        $merchant->getWallet('merchant')->depositFloat(5000.0);
    });

    app(MerchantIssuanceService::class)->issue(new MerchantIssuanceRequest(
        merchantTenant: Tenant::findOrFail(Tests\AccountingTestCase::sharedMerchantTenantId()),
        agencyTenant: Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId()),
        provider: $provider,
        productType: 'airline',
        sellingPrice: 1200.0,
        vatAmount: 109.0,
        wholesalePrice: 1000.0,
        providerCost: 950.0,
        providerReference: 'PNR-P9AG-'.uniqid(),
        currency: 'LYD',
    ));

    withTenantP9(Tests\AccountingTestCase::sharedTenantId(), function () {
        $aging = app(MerchantSettlementAgingReport::class)->generate();
        expect($aging['outstanding'])->toBeGreaterThanOrEqual(1000.0);
    });
});

test('merchant settlement aging outstanding clears after settlement', function () {
    $provider = makeAgencyProviderP9(10000.0);

    withTenantP9(Tests\AccountingTestCase::sharedMerchantTenantId(), function () {
        $merchant = Tenant::findOrFail(Tests\AccountingTestCase::sharedMerchantTenantId());
        $merchant->getWallet('merchant')->depositFloat(5000.0);
    });

    app(MerchantIssuanceService::class)->issue(new MerchantIssuanceRequest(
        merchantTenant: Tenant::findOrFail(Tests\AccountingTestCase::sharedMerchantTenantId()),
        agencyTenant: Tenant::findOrFail(Tests\AccountingTestCase::sharedTenantId()),
        provider: $provider,
        productType: 'airline',
        sellingPrice: 1200.0,
        vatAmount: 109.0,
        wholesalePrice: 1000.0,
        providerCost: 950.0,
        providerReference: 'PNR-P9AS-'.uniqid(),
        currency: 'LYD',
    ));

    $outstandingBefore = 0.0;
    withTenantP9(Tests\AccountingTestCase::sharedTenantId(), function () use (&$outstandingBefore) {
        $outstandingBefore = app(MerchantSettlementAgingReport::class)->generate()['outstanding'];
    });

    app(SettlementService::class)->settleMerchantToAgency(
        merchantTenantId: Tests\AccountingTestCase::sharedMerchantTenantId(),
        agencyTenantId: Tests\AccountingTestCase::sharedTenantId(),
        amount: 1000.0,
        batchReference: 'BATCH-P9-'.uniqid(),
    );

    withTenantP9(Tests\AccountingTestCase::sharedTenantId(), function () use ($outstandingBefore) {
        $outstandingAfter = app(MerchantSettlementAgingReport::class)->generate()['outstanding'];
        expect($outstandingAfter)->toBeLessThan($outstandingBefore);
    });
});
