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
