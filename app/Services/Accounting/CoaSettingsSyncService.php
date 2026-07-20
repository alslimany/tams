<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CoaSettingsSyncService
{
    /**
     * Maps the first digit of an account code to a financial statement type.
     *
     * @var array<string, string>
     */
    public const TYPE_MAP = [
        '1' => 'asset',
        '2' => 'liability',
        '3' => 'equity',
        '4' => 'revenue',
        '5' => 'expense',
        '6' => 'purchase',
        '7' => 'expense',
        '8' => 'asset',
    ];

    /**
     * Mirror every ledger_accounts row into coa_settings, creating rows that are
     * missing and refreshing the code/parent linkage of existing rows.
     *
     * @return int Number of coa_settings rows created.
     */
    public function syncFromLedger(bool $markSystem = true): int
    {
        if (! Schema::hasTable('coa_settings') || ! Schema::hasTable('ledger_accounts')) {
            return 0;
        }

        $accounts = DB::table('ledger_accounts')
            ->whereNull('deleted_at')
            ->where('code', '!=', '')
            ->orderBy('code')
            ->get(['ledgerUuid', 'code', 'parentUuid', 'category']);

        $uuidToCode = $accounts->pluck('code', 'ledgerUuid');

        $names = DB::table('ledger_names')
            ->whereIn('ownerUuid', $accounts->pluck('ledgerUuid'))
            ->orderBy('language')
            ->get(['ownerUuid', 'name'])
            ->groupBy('ownerUuid')
            ->map(fn ($group) => $group->first()->name);

        $existingCodes = DB::table('coa_settings')->pluck('code')->flip();
        $created = 0;

        foreach ($accounts as $account) {
            $attributes = [
                'ledger_uuid' => $account->ledgerUuid,
                'display_name' => $names[$account->ledgerUuid] ?? $account->code,
                'account_type' => self::TYPE_MAP[substr((string) $account->code, 0, 1)] ?? 'asset',
                'parent_code' => $account->parentUuid ? ($uuidToCode[$account->parentUuid] ?? null) : null,
                'sort_order' => (int) $account->code,
                'updated_at' => now(),
            ];

            if (isset($existingCodes[$account->code])) {
                DB::table('coa_settings')->where('code', $account->code)->update($attributes);

                continue;
            }

            DB::table('coa_settings')->insert($attributes + [
                'code' => $account->code,
                'is_system' => $markSystem,
                'is_active' => true,
                'created_at' => now(),
            ]);
            $created++;
        }

        return $created;
    }
}
