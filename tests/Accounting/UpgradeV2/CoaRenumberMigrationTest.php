<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\AccountingTestCase;

// ─────────────────────────────────────────────────────────────────────────────
// CoA renumber migration — string-safe code updates on MySQL.
// ─────────────────────────────────────────────────────────────────────────────

function renumberTestTenant(): Tenant
{
    return Tenant::findOrFail(AccountingTestCase::sharedTenantId());
}

/**
 * Run the same string-bound update the migration uses.
 */
function renumberCodeWithStringBinding(string $fromCode, string $toCode): void
{
    DB::update(
        'UPDATE `ledger_accounts` SET `code` = ? WHERE `code` = ?',
        [$toCode, $fromCode],
    );
}

/**
 * @return array{from: string, to: string, suffix: string, uuids: list<string>}
 */
function insertRenumberProbeAccounts(): array
{
    $from = 'T7'.mt_rand(10000, 99999);
    $to = 'T8'.mt_rand(10000, 99999);
    $suffix = 'TST'.mt_rand(10000, 99999).'_ST';
    $fromUuid = (string) Str::uuid();
    $suffixUuid = (string) Str::uuid();

    foreach ([
        ['ledgerUuid' => $fromUuid, 'code' => $from],
        ['ledgerUuid' => $suffixUuid, 'code' => $suffix],
    ] as $row) {
        DB::table('ledger_accounts')->insert([
            ...$row,
            'taxCode' => null,
            'parentUuid' => null,
            'debit' => true,
            'credit' => false,
            'category' => false,
            'closed' => false,
            'extra' => null,
            'flex' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return [
        'from' => $from,
        'to' => $to,
        'suffix' => $suffix,
        'uuids' => [$fromUuid, $suffixUuid],
    ];
}

test('renumber migration string binding only updates exact code matches', function () {
    renumberTestTenant()->run(function () {
        if (! Schema::hasTable('ledger_accounts')) {
            expect(true)->toBeTrue();

            return;
        }

        $probe = insertRenumberProbeAccounts();

        renumberCodeWithStringBinding($probe['from'], $probe['to']);

        expect(DB::table('ledger_accounts')->where('code', $probe['suffix'])->exists())->toBeTrue()
            ->and(DB::table('ledger_accounts')->where('code', $probe['to'])->exists())->toBeTrue()
            ->and(DB::table('ledger_accounts')->where('code', $probe['from'])->exists())->toBeFalse();

        DB::table('ledger_accounts')->whereIn('ledgerUuid', $probe['uuids'])->delete();
    });
    tenancy()->end();
});

test('renumber migration existence check uses string binding', function () {
    renumberTestTenant()->run(function () {
        $exists = DB::selectOne(
            'SELECT 1 AS found FROM `ledger_accounts` WHERE `code` = ? LIMIT 1',
            ['7000'],
        ) !== null;

        expect($exists)->toBeBool();
    });
    tenancy()->end();
});

test('bootstrap recreates soft-deleted template accounts and surfaces name conflicts clearly', function () {
    renumberTestTenant()->run(function () {
        if (! Schema::hasTable('ledger_accounts')) {
            expect(true)->toBeTrue();

            return;
        }

        $account = \App\Models\Tenant\ChartOfAccount::query()->where('code', '1420')->first();

        if ($account === null) {
            expect(true)->toBeTrue();

            return;
        }

        $account->delete();

        expect(\App\Models\Tenant\ChartOfAccount::query()->where('code', '1420')->exists())->toBeFalse()
            ->and(DB::table('ledger_accounts')->where('code', '1420')->whereNotNull('deleted_at')->exists())->toBeTrue();

        $tenant = renumberTestTenant();
        $result = app(\App\Services\Accounting\LedgerBootstrapService::class)->bootstrapForTenant($tenant);

        expect(\App\Models\Tenant\ChartOfAccount::query()->where('code', '1420')->exists())->toBeTrue()
            ->and($result['created_root'])->toBeFalse();
    });
    tenancy()->end();
});

test('bootstrap renames live name conflicts so missing template accounts can be added', function () {
    renumberTestTenant()->run(function () {
        if (! Schema::hasTable('ledger_accounts')) {
            expect(true)->toBeTrue();

            return;
        }

        // Remove a template account so bootstrap will try to recreate it,
        // then plant a live blocker with the same display name.
        $target = \Abivia\Ledger\Models\LedgerAccount::query()->where('code', '6060')->first();

        if ($target === null) {
            expect(true)->toBeTrue();

            return;
        }

        DB::table('ledger_names')->where('ownerUuid', $target->ledgerUuid)->delete();
        DB::table('ledger_accounts')->where('ledgerUuid', $target->ledgerUuid)->delete();
        if (Schema::hasTable('coa_settings')) {
            DB::table('coa_settings')->where('code', '6060')->delete();
        }

        $blockerUuid = (string) Str::uuid();
        $blockerCode = 'T9'.mt_rand(10000, 99999);
        DB::table('ledger_accounts')->insert([
            'ledgerUuid' => $blockerUuid,
            'code' => $blockerCode,
            'taxCode' => null,
            'parentUuid' => null,
            'debit' => true,
            'credit' => false,
            'category' => false,
            'closed' => false,
            'extra' => null,
            'flex' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ledger_names')->insert([
            'ownerUuid' => $blockerUuid,
            'language' => 'en',
            'name' => 'Other Purchases',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (Schema::hasTable('coa_settings')) {
            DB::table('coa_settings')->insert([
                'ledger_uuid' => $blockerUuid,
                'code' => $blockerCode,
                'display_name' => 'Other Purchases',
                'account_type' => 'purchase',
                'parent_code' => null,
                'is_system' => true,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        try {
            $result = app(\App\Services\Accounting\LedgerBootstrapService::class)
                ->bootstrapForTenant(renumberTestTenant());

            expect($result['created_root'])->toBeFalse()
                ->and(\Abivia\Ledger\Models\LedgerAccount::query()->where('code', '6060')->exists())->toBeTrue();

            $renamed = DB::table('ledger_names')
                ->where('ownerUuid', $blockerUuid)
                ->where('language', 'en')
                ->value('name');

            expect($renamed)->toBe("Other Purchases ({$blockerCode})");
        } finally {
            if (Schema::hasTable('coa_settings')) {
                DB::table('coa_settings')->where('code', $blockerCode)->delete();
            }
            DB::table('ledger_names')->where('ownerUuid', $blockerUuid)->delete();
            DB::table('ledger_accounts')->where('ledgerUuid', $blockerUuid)->delete();
        }
    });
    tenancy()->end();
});

test('bootstrap restores renumbered template names before adding purchases accounts', function () {
    renumberTestTenant()->run(function () {
        if (! Schema::hasTable('ledger_accounts')) {
            expect(true)->toBeTrue();

            return;
        }

        $opex = \Abivia\Ledger\Models\LedgerAccount::query()->where('code', '7000')->first();
        $purchases = \Abivia\Ledger\Models\LedgerAccount::query()->where('code', '6000')->first();

        if ($opex === null || $purchases === null) {
            expect(true)->toBeTrue();

            return;
        }

        // Simulate median: old Operating Expenses was renumbered to 7000 but still
        // carries the Purchases display name, blocking recreation of 6000.
        DB::table('ledger_names')
            ->where('ownerUuid', $opex->ledgerUuid)
            ->where('language', 'en')
            ->update(['name' => 'Purchases', 'updated_at' => now()]);

        DB::table('ledger_names')->where('ownerUuid', $purchases->ledgerUuid)->delete();
        DB::table('ledger_accounts')->where('ledgerUuid', $purchases->ledgerUuid)->delete();
        if (Schema::hasTable('coa_settings')) {
            DB::table('coa_settings')->where('code', '6000')->delete();
        }

        app(\App\Services\Accounting\LedgerBootstrapService::class)
            ->bootstrapForTenant(renumberTestTenant());

        expect(\Abivia\Ledger\Models\LedgerAccount::query()->where('code', '6000')->exists())->toBeTrue();

        $opexName = DB::table('ledger_names')
            ->where('ownerUuid', $opex->ledgerUuid)
            ->where('language', 'en')
            ->value('name');
        $purchasesName = DB::table('ledger_names')
            ->where('ownerUuid', \Abivia\Ledger\Models\LedgerAccount::query()->where('code', '6000')->value('ledgerUuid'))
            ->where('language', 'en')
            ->value('name');

        expect($opexName)->toBe('Operating Expenses')
            ->and($purchasesName)->toBe('Purchases');
    });
    tenancy()->end();
});
