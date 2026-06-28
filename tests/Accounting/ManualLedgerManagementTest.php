<?php

use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\JournalEntry;
use Abivia\Ledger\Models\LedgerAccount;
use Abivia\Ledger\Models\LedgerBalance;
use App\DTOs\Accounting\IssuanceDTO;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use Tests\AccountingTestCase;

// ─────────────────────────────────────────────────────────────────────────────
// Manual Chart-of-Accounts and Journal-Entry management.
//
// Exercises the real HTTP stack (routes + role:admin + tenancy + LedgerController)
// against the shared accounting tenant. Asserts that user-created accounts and
// journal entries are posted through the abivia ledger so that ledger_balances
// stay in sync, that system-generated entries are protected, and that accounts
// with posted transactions cannot be deleted.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resolve the shared tenant (central context).
 */
function acctTenant(): Tenant
{
    return Tenant::findOrFail(AccountingTestCase::sharedTenantId());
}

/**
 * Build an absolute URL on the shared tenant's domain for a tenant route.
 */
function tenantRoute(string $name, array|string|int $params = []): string
{
    $tenant = acctTenant();

    return 'http://'.$tenant->domains->first()->domain.route($name, $params, false);
}

/**
 * A transaction date guaranteed to be after the ledger's opening date
 * (which equals the tenant-creation timestamp during tests).
 */
function postingDate(): string
{
    return now()->addDay()->toDateString();
}

/**
 * Create (once) and return an admin user living in the shared tenant database.
 */
function acctAdmin(): User
{
    $admin = null;
    acctTenant()->run(function () use (&$admin) {
        $admin = User::query()->where('email', 'ledger-admin@example.test')->first()
            ?? User::factory()->create([
                'email' => 'ledger-admin@example.test',
                'role' => 'admin',
                'is_active' => true,
            ]);
    });
    tenancy()->end();

    return $admin;
}

/**
 * Generate a unique numeric account code in the expense range for the
 * process-wide shared tenant (which persists across tests).
 */
function uniqueExpenseCode(): string
{
    return '6'.mt_rand(1000, 9999);
}

// ─── Chart of Accounts ───────────────────────────────────────────────────────

test('admin can create a manual expense account (regression: creation no longer throws)', function () {
    $code = uniqueExpenseCode();

    $this->actingAs(acctAdmin())
        ->post(tenantRoute('accounting.ledger.coa.store'), [
            'code' => $code,
            'name' => 'Office Rent',
            'type' => 'expense',
            'parent' => '6000',
        ])
        ->assertSessionHas('success');

    acctTenant()->run(function () use ($code) {
        $account = LedgerAccount::where('code', $code)->first();

        expect($account)->not->toBeNull()
            ->and((bool) $account->debit)->toBeTrue()
            ->and((bool) $account->credit)->toBeFalse();
    });
    tenancy()->end();
});

test('admin can create a manual revenue account as a credit account', function () {
    $code = '4'.mt_rand(1000, 9999);

    $this->actingAs(acctAdmin())
        ->post(tenantRoute('accounting.ledger.coa.store'), [
            'code' => $code,
            'name' => 'Consulting Revenue',
            'type' => 'revenue',
        ])
        ->assertSessionHas('success');

    acctTenant()->run(function () use ($code) {
        $account = LedgerAccount::where('code', $code)->first();

        expect($account)->not->toBeNull()
            ->and((bool) $account->credit)->toBeTrue()
            ->and((bool) $account->debit)->toBeFalse();
    });
    tenancy()->end();
});

test('creating an account rejects an invalid type', function () {
    $this->actingAs(acctAdmin())
        ->post(tenantRoute('accounting.ledger.coa.store'), [
            'code' => uniqueExpenseCode(),
            'name' => 'Bad Account',
            'type' => 'banana',
        ])
        ->assertSessionHasErrors('type');
});

// ─── Manual Journal Entries ──────────────────────────────────────────────────

test('admin can post a balanced manual journal entry and ledger balances update', function () {
    $code = uniqueExpenseCode();
    $admin = acctAdmin();

    // Create the expense account first.
    $this->actingAs($admin)
        ->post(tenantRoute('accounting.ledger.coa.store'), [
            'code' => $code,
            'name' => 'Salaries',
            'type' => 'expense',
            'parent' => '6000',
        ])
        ->assertSessionHas('success');

    // Post: debit Salaries 1000, credit Operating Wallet (1110) 1000.
    $this->actingAs($admin)
        ->post(tenantRoute('accounting.ledger.journal.store'), [
            'transDate' => postingDate(),
            'description' => 'Monthly salaries',
            'journal' => 'GEN',
            'lines' => [
                ['accountCode' => $code, 'debit' => 1000, 'credit' => null],
                ['accountCode' => '1110', 'debit' => null, 'credit' => 1000],
            ],
        ])
        ->assertSessionHas('success');

    acctTenant()->run(function () use ($code) {
        $uuid = LedgerAccount::where('code', $code)->value('ledgerUuid');

        // ledger_balances (used by abivia trial balance) must reflect the debit.
        $balance = (float) LedgerBalance::where('ledgerUuid', $uuid)->sum('balance');
        expect(round($balance, 3))->toBe(-1000.0); // debit stored negative

        $entry = JournalEntry::whereHas('details', fn ($q) => $q->where('ledgerUuid', $uuid))->latest('journalEntryId')->first();
        $extra = json_decode($entry->extra, true);
        expect($extra['source'] ?? null)->toBe('manual');
    });
    tenancy()->end();
});

test('updating a manual entry keeps ledger balances in sync (no double counting)', function () {
    $code = uniqueExpenseCode();
    $admin = acctAdmin();

    $this->actingAs($admin)->post(tenantRoute('accounting.ledger.coa.store'), [
        'code' => $code, 'name' => 'Utilities', 'type' => 'expense', 'parent' => '6000',
    ])->assertSessionHas('success');

    $this->actingAs($admin)->post(tenantRoute('accounting.ledger.journal.store'), [
        'transDate' => postingDate(),
        'description' => 'Utilities bill',
        'journal' => 'GEN',
        'lines' => [
            ['accountCode' => $code, 'debit' => 300, 'credit' => null],
            ['accountCode' => '1110', 'debit' => null, 'credit' => 300],
        ],
    ])->assertSessionHas('success');

    $entryId = null;
    acctTenant()->run(function () use ($code, &$entryId) {
        $uuid = LedgerAccount::where('code', $code)->value('ledgerUuid');
        $entryId = JournalEntry::whereHas('details', fn ($q) => $q->where('ledgerUuid', $uuid))->value('journalEntryId');
    });
    tenancy()->end();

    // Update the amount from 300 to 750.
    $this->actingAs($admin)->put(tenantRoute('accounting.ledger.journal.update', $entryId), [
        'transDate' => postingDate(),
        'description' => 'Utilities bill (corrected)',
        'lines' => [
            ['accountCode' => $code, 'debit' => 750, 'credit' => null],
            ['accountCode' => '1110', 'debit' => null, 'credit' => 750],
        ],
    ])->assertSessionHas('success');

    acctTenant()->run(function () use ($code) {
        $uuid = LedgerAccount::where('code', $code)->value('ledgerUuid');
        $balance = (float) LedgerBalance::where('ledgerUuid', $uuid)->sum('balance');

        // Must be exactly -750 (not -1050 if the old entry weren't reversed).
        expect(round($balance, 3))->toBe(-750.0);
    });
    tenancy()->end();
});

test('deleting a manual entry reverses its ledger balance impact', function () {
    $code = uniqueExpenseCode();
    $admin = acctAdmin();

    $this->actingAs($admin)->post(tenantRoute('accounting.ledger.coa.store'), [
        'code' => $code, 'name' => 'Marketing', 'type' => 'expense', 'parent' => '6000',
    ])->assertSessionHas('success');

    $this->actingAs($admin)->post(tenantRoute('accounting.ledger.journal.store'), [
        'transDate' => postingDate(),
        'description' => 'Ad spend',
        'journal' => 'GEN',
        'lines' => [
            ['accountCode' => $code, 'debit' => 500, 'credit' => null],
            ['accountCode' => '1110', 'debit' => null, 'credit' => 500],
        ],
    ])->assertSessionHas('success');

    $entryId = null;
    acctTenant()->run(function () use ($code, &$entryId) {
        $uuid = LedgerAccount::where('code', $code)->value('ledgerUuid');
        $entryId = JournalEntry::whereHas('details', fn ($q) => $q->where('ledgerUuid', $uuid))->value('journalEntryId');
    });
    tenancy()->end();

    $this->actingAs($admin)
        ->delete(tenantRoute('accounting.ledger.journal.destroy', $entryId))
        ->assertSessionHas('success');

    acctTenant()->run(function () use ($code, $entryId) {
        $uuid = LedgerAccount::where('code', $code)->value('ledgerUuid');
        $balance = (float) LedgerBalance::where('ledgerUuid', $uuid)->sum('balance');

        expect(round($balance, 3))->toBe(0.0)
            ->and(JournalEntry::find($entryId))->toBeNull()
            ->and(JournalDetail::where('journalEntryId', $entryId)->count())->toBe(0);
    });
    tenancy()->end();
});

// ─── Protection of system-generated entries ──────────────────────────────────

test('system-generated journal entries cannot be edited or deleted', function () {
    $admin = acctAdmin();

    $entryId = null;
    acctTenant()->run(function () use (&$entryId) {
        $entry = app(LedgerPostingService::class)->postIssuanceEntry(new IssuanceDTO(
            orderId: 'ORD-PROTECT-'.\Illuminate\Support\Str::random(4),
            productType: 'airline',
            sellingPrice: 1000.000,
            vatAmount: 91.000,
            providerCost: 800.000,
            providerReference: 'PNR-PROTECT',
        ));
        $entryId = $entry->journalEntryId;
    });
    tenancy()->end();

    $this->actingAs($admin)->put(tenantRoute('accounting.ledger.journal.update', $entryId), [
        'transDate' => postingDate(),
        'description' => 'tampering',
        'lines' => [
            ['accountCode' => '1110', 'debit' => 1, 'credit' => null],
            ['accountCode' => '4100', 'debit' => null, 'credit' => 1],
        ],
    ])->assertSessionHas('error');

    $this->actingAs($admin)
        ->delete(tenantRoute('accounting.ledger.journal.destroy', $entryId))
        ->assertSessionHas('error');

    acctTenant()->run(function () use ($entryId) {
        expect(JournalEntry::find($entryId))->not->toBeNull();
    });
    tenancy()->end();
});

// ─── Protection of accounts with transactions ────────────────────────────────

test('an account with posted transactions cannot be deleted', function () {
    $code = uniqueExpenseCode();
    $admin = acctAdmin();

    $this->actingAs($admin)->post(tenantRoute('accounting.ledger.coa.store'), [
        'code' => $code, 'name' => 'Travel', 'type' => 'expense', 'parent' => '6000',
    ])->assertSessionHas('success');

    $this->actingAs($admin)->post(tenantRoute('accounting.ledger.journal.store'), [
        'transDate' => postingDate(),
        'description' => 'Taxi',
        'journal' => 'GEN',
        'lines' => [
            ['accountCode' => $code, 'debit' => 25, 'credit' => null],
            ['accountCode' => '1110', 'debit' => null, 'credit' => 25],
        ],
    ])->assertSessionHas('success');

    $this->actingAs($admin)
        ->delete(tenantRoute('accounting.ledger.coa.destroy', $code))
        ->assertSessionHas('error');

    acctTenant()->run(function () use ($code) {
        expect(LedgerAccount::where('code', $code)->exists())->toBeTrue();
    });
    tenancy()->end();
});
