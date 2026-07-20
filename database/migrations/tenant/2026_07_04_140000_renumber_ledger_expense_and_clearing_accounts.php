<?php

use App\Models\Tenant;
use App\Services\Accounting\LedgerBootstrapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Renumber legacy account codes to make room for the Purchases range (6xxx):
     *   7xxx Settlement Clearing → 8xxx, then 6xxx Operating Expenses → 7xxx.
     * Afterwards, add the new template accounts (purchases, inventory, payables, taxes)
     * to already-bootstrapped tenant ledgers.
     *
     * @var array<string, string>
     */
    private array $renumberMap = [
        '7000' => '8000',
        '7100' => '8100',
        '7200' => '8200',
        '7400' => '8400',
        '6000' => '7000',
        '6100' => '7100',
        '6200' => '7200',
        '6300' => '7300',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ledger_accounts')) {
            return;
        }

        if (! DB::table('ledger_accounts')->exists()) {
            return;
        }

        foreach ($this->renumberMap as $oldCode => $newCode) {
            $this->renumberCode($oldCode, $newCode);
        }

        $tenant = tenant();

        if ($tenant instanceof Tenant) {
            app(LedgerBootstrapService::class)->bootstrapForTenant($tenant);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ledger_accounts')) {
            return;
        }

        foreach (array_reverse($this->renumberMap, true) as $oldCode => $newCode) {
            $this->renumberCode($newCode, $oldCode);
        }
    }

    /**
     * Move a single account code. Uses bound string parameters so MySQL does not
     * compare the VARCHAR `code` column as a number (which breaks on values like
     * "2200_ST" when searching for 7000).
     */
    private function renumberCode(string $fromCode, string $toCode): void
    {
        $fromCode = (string) $fromCode;
        $toCode = (string) $toCode;

        if (! $this->codeExists($fromCode)) {
            return;
        }

        if ($this->codeExists($toCode)) {
            return;
        }

        DB::update(
            'UPDATE `ledger_accounts` SET `code` = ? WHERE `code` = ?',
            [$toCode, $fromCode],
        );
    }

    private function codeExists(string $code): bool
    {
        return DB::selectOne(
            'SELECT 1 AS found FROM `ledger_accounts` WHERE `code` = ? LIMIT 1',
            [(string) $code],
        ) !== null;
    }
};
