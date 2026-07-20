<?php

use Abivia\Ledger\Models\JournalDetail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\Reports\BalanceSheetService;
use App\Services\Accounting\Reports\GeneralLedgerService;
use App\Services\Accounting\Reports\IncomeStatementService;
use Tests\AccountingTestCase;

// ─────────────────────────────────────────────────────────────────────────────
// Upgrade v2 — General Ledger, Balance Sheet, Income Statement correctness.
// Covers plan §7 "Financial Reports" checklist items.
//
// Entries are posted on far-future dates so report period windows are isolated
// from entries created by other tests sharing the same tenant.
// ─────────────────────────────────────────────────────────────────────────────

function uv2ReportsTenant(): Tenant
{
    return Tenant::findOrFail(AccountingTestCase::sharedTenantId());
}

function uv2ReportsRoute(string $name, array|string|int $params = []): string
{
    $tenant = uv2ReportsTenant();

    return 'http://'.$tenant->domains->first()->domain.route($name, $params, false);
}

function uv2ReportsAdmin(): User
{
    $admin = null;
    uv2ReportsTenant()->run(function () use (&$admin) {
        $admin = User::query()->where('email', 'uv2-reports-admin@example.test')->first()
            ?? User::factory()->create([
                'email' => 'uv2-reports-admin@example.test',
                'role' => 'admin',
                'is_active' => true,
            ]);
    });
    tenancy()->end();

    return $admin;
}

/**
 * Post a balanced two-line manual journal entry through the HTTP stack.
 */
function uv2PostEntry(User $admin, string $date, string $debitCode, string $creditCode, float $amount, string $description): void
{
    test()->actingAs($admin)
        ->post(uv2ReportsRoute('accounting.ledger.journal.store'), [
            'transDate' => $date,
            'description' => $description,
            'journal' => 'GEN',
            'lines' => [
                ['accountCode' => $debitCode, 'debit' => $amount, 'credit' => null],
                ['accountCode' => $creditCode, 'debit' => null, 'credit' => $amount],
            ],
        ])
        ->assertSessionHas('success');
}

test('general ledger shows correct opening balance and running balance per line', function () {
    $admin = uv2ReportsAdmin();
    $code = '752'.mt_rand(10, 99);

    $this->actingAs($admin)
        ->post(uv2ReportsRoute('accounting.ledger.coa.store'), [
            'code' => $code,
            'name' => 'UV2 GL Expense',
            'type' => 'expense',
            'parent' => '7000',
        ])
        ->assertSessionHas('success');

    $dayOne = now()->addDays(30)->toDateString();
    $dayTwo = now()->addDays(31)->toDateString();

    uv2PostEntry($admin, $dayOne, $code, '1110', 100.0, 'UV2 GL entry one');
    uv2PostEntry($admin, $dayTwo, $code, '1110', 50.0, 'UV2 GL entry two');

    uv2ReportsTenant()->run(function () use ($code, $dayTwo) {
        $accounts = app(GeneralLedgerService::class)->generate($dayTwo, $dayTwo, [$code]);

        expect($accounts)->toHaveCount(1);

        $account = $accounts[0];

        // Debits are stored negative (credit-positive convention).
        expect($account['openingBalance'])->toBe(-100.0)
            ->and($account['lines'])->toHaveCount(1)
            ->and($account['lines'][0]['debit'])->toBe(50.0)
            ->and($account['lines'][0]['runningBalance'])->toBe(-150.0)
            ->and($account['closingBalance'])->toBe(-150.0)
            ->and($account['totalDebit'])->toBe(50.0);
    });
    tenancy()->end();
});

test('trial balance invariant holds: total debits equal total credits', function () {
    uv2ReportsTenant()->run(function () {
        $debits = abs((float) JournalDetail::where('amount', '<', 0)->sum('amount'));
        $credits = (float) JournalDetail::where('amount', '>', 0)->sum('amount');

        expect(round($debits, 3))->toBe(round($credits, 3));
    });
    tenancy()->end();
});

test('balance sheet balances: assets equal liabilities plus equity', function () {
    $admin = uv2ReportsAdmin();
    $date = now()->addDays(32)->toDateString();

    // Post activity across asset, revenue, and expense accounts.
    uv2PostEntry($admin, $date, '1110', '4100', 750.0, 'UV2 BS revenue');
    uv2PostEntry($admin, $date, '7300', '1110', 120.0, 'UV2 BS expense');

    uv2ReportsTenant()->run(function () use ($date) {
        $report = app(BalanceSheetService::class)->generate($date);

        expect($report['isBalanced'])->toBeTrue()
            ->and(round($report['totals']['assets'], 3))
            ->toBe(round($report['totals']['liabilities_and_equity'], 3));
    });
    tenancy()->end();
});

test('income statement computes net profit = revenue − COGS − purchases − opex', function () {
    $admin = uv2ReportsAdmin();
    $date = now()->addDays(40)->toDateString();

    uv2PostEntry($admin, $date, '1110', '4100', 500.0, 'UV2 IS revenue');
    uv2PostEntry($admin, $date, '7500', '1110', 200.0, 'UV2 IS opex');
    uv2PostEntry($admin, $date, '6060', '1110', 80.0, 'UV2 IS purchase');

    uv2ReportsTenant()->run(function () use ($date) {
        $report = app(IncomeStatementService::class)->generate($date, $date);

        expect($report['revenue']['total'])->toBe(500.0)
            ->and($report['cogs']['total'])->toBe(0.0)
            ->and($report['grossProfit'])->toBe(500.0)
            ->and($report['purchases']['total'])->toBe(80.0)
            ->and($report['opex']['total'])->toBe(200.0)
            ->and($report['netProfit'])->toBe(220.0);
    });
    tenancy()->end();
});

test('income statement respects the period filter', function () {
    uv2ReportsTenant()->run(function () {
        $emptyDay = now()->addDays(60)->toDateString();
        $report = app(IncomeStatementService::class)->generate($emptyDay, $emptyDay);

        expect($report['revenue']['total'])->toBe(0.0)
            ->and($report['netProfit'])->toBe(0.0);
    });
    tenancy()->end();
});
