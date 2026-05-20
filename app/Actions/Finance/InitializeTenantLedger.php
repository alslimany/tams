<?php

namespace App\Actions\Finance;

use Abivia\Ledger\Exceptions\Breaker;
use Abivia\Ledger\Http\Controllers\LedgerAccountController;
use Abivia\Ledger\Messages\Account;
use Abivia\Ledger\Messages\Create;
use Abivia\Ledger\Messages\Currency;
use Abivia\Ledger\Messages\Name;
use Abivia\Ledger\Models\LedgerAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class InitializeTenantLedger
{
    /**
     * @return array{created_root: bool, added_accounts: int, total_required_accounts: int}
     *
     * @throws Breaker
     */
    public function execute(string $defaultCurrency = 'USD'): array
    {
        $this->assertLedgerTablesExist();

        $originalDateClass = get_class(Date::now());
        Date::use(Carbon::class);

        try {
            $requiredAccounts = $this->requiredAccounts();
            $createdRoot = false;

            if (! LedgerAccount::hasRoot()) {
                $create = new Create;
                $create->names[] = Name::fromArray(['name' => 'Tenant Ledger']);
                $create->transDate = Carbon::now();
                $create->currencies[] = Currency::fromArray([
                    'code' => strtoupper($defaultCurrency),
                    'decimals' => 2,
                ]);

                foreach ($requiredAccounts as $definition) {
                    $create->accounts[] = Account::fromArray($definition);
                }

                (new LedgerAccountController)->create($create);
                $createdRoot = true;
            }

            $existingCodes = LedgerAccount::query()->pluck('code')->filter()->all();
            $addedAccounts = 0;

            foreach ($requiredAccounts as $definition) {
                if (in_array($definition['code'], $existingCodes, true)) {
                    continue;
                }

                (new LedgerAccountController)->add(Account::fromArray($definition));
                $existingCodes[] = $definition['code'];
                $addedAccounts++;
            }

            return [
                'created_root' => $createdRoot,
                'added_accounts' => $addedAccounts,
                'total_required_accounts' => $requiredAccounts->count(),
            ];
        } finally {
            Date::use($originalDateClass);
        }
    }

    /**
     * @return Collection<int, array{code: string, name: string, debit?: bool, credit?: bool}>
     */
    protected function requiredAccounts(): Collection
    {
        return collect([
            ['code' => '1300', 'debit' => true, 'name' => 'Wallet Assets - Tenant Wallets'],
            ['code' => '2200', 'credit' => true, 'name' => 'Tax Payable'],
            ['code' => '2200_ST', 'credit' => true, 'name' => 'Tax Payable - ST'],
            ['code' => '2410', 'credit' => true, 'name' => 'Airline Tax Payable'],
            ['code' => '2300', 'credit' => true, 'name' => 'Commission Payable'],
            ['code' => '3100', 'credit' => true, 'name' => 'Revenue - Flights'],
            ['code' => '3190', 'credit' => true, 'name' => 'Revenue - Other'],
            ['code' => '6100', 'debit' => true, 'name' => 'Commission Expense'],
        ]);
    }

    protected function assertLedgerTablesExist(): void
    {
        $requiredTables = [
            'ledger_accounts',
            'ledger_currencies',
            'ledger_domains',
            'journal_entries',
            'journal_details',
        ];

        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(
                    "Ledger table '{$table}' does not exist in tenant database. Run tenant ledger migrations first."
                );
            }
        }
    }
}
