<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Accounting\AccountRoutingDefaults;
use App\Services\Accounting\CoaSettingsSyncService;
use Illuminate\Console\Command;
use Throwable;

class AccountingBackfillRoutingCommand extends Command
{
    protected $signature = 'accounting:backfill-routing
        {tenantId? : Optional tenant ID. If omitted, all tenants are processed}
        {--force : Reset existing routing rows back to the seeded defaults}';

    protected $description = 'Seed missing account routing defaults and coa_settings rows in tenant databases (idempotent).';

    public function handle(AccountRoutingDefaults $routingDefaults, CoaSettingsSyncService $coaSync): int
    {
        $tenantId = $this->argument('tenantId');
        $force = (bool) $this->option('force');

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
            $this->line("Backfilling accounting routing for tenant: {$tenant->id}");

            try {
                [$routingRows, $coaRows] = $tenant->run(fn () => [
                    $routingDefaults->seed($force),
                    $coaSync->syncFromLedger(markSystem: true),
                ]);

                $this->info("  done: routing_rows={$routingRows}, coa_settings_rows={$coaRows}");
            } catch (Throwable $exception) {
                $failed = true;
                $this->error("  failed: {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
