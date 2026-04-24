<?php

namespace App\Console\Commands;

use App\Actions\Finance\InitializeTenantLedger;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

class InitializeTenantLedgerCommand extends Command
{
    protected $signature = 'ledger:initialize-tenants
        {tenantId? : Optional tenant ID. If omitted, all tenants are processed}
        {--currency=USD : Default ledger currency code used when creating a new root}';

    protected $description = 'Initialize Abivia ledger root/accounts in tenant databases (idempotent).';

    public function handle(InitializeTenantLedger $initializer): int
    {
        $tenantId = $this->argument('tenantId');
        $currency = strtoupper((string) $this->option('currency'));

        $tenants = Tenant::query()
            ->when(is_string($tenantId) && $tenantId !== '', fn ($query) => $query->where('id', $tenantId))
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->error('No tenants matched the provided criteria.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($tenants as $tenant) {
            $this->line("Initializing ledger for tenant: {$tenant->id}");

            try {
                $result = $tenant->run(fn () => $initializer->execute($currency));

                $createdRoot = (bool) ($result['created_root'] ?? false);
                $addedAccounts = (int) ($result['added_accounts'] ?? 0);
                $totalRequired = (int) ($result['total_required_accounts'] ?? 0);

                $this->info(
                    '  done: root_created='.($createdRoot ? 'yes' : 'no')
                    .", added_accounts={$addedAccounts}, required_accounts={$totalRequired}"
                );
            } catch (Throwable $exception) {
                $failed = true;
                $this->error("  failed: {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
