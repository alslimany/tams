<?php

namespace App\Console\Commands;

use App\DTOs\Accounting\IssuanceDTO;
use App\Models\Tenant;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Console\Command;

class BackfillMigratedLedgerCommand extends Command
{
    protected $signature = 'migration:backfill-ledger {tenant_id : The tenant ID to backfill ledger entries for}';

    protected $description = 'Backfill ledger entries for orders migrated from the legacy system';

    public function handle(): int
    {
        $tenantId = $this->argument('tenant_id');
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            $this->error("Tenant [{$tenantId}] not found.");

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        $items = \App\Models\OrderItem::whereNotNull('commission_amount')
            ->whereNull('ledger_entry_id')
            ->where('status', 'issued')
            ->get();

        $this->info("Found {$items->count()} items to backfill for tenant [{$tenantId}].");

        $bar = $this->output->createProgressBar($items->count());
        $succeeded = 0;
        $failed = 0;

        foreach ($items as $item) {
            try {
                $dto = new IssuanceDTO(
                    orderId: $item->order_id,
                    productType: $item->product_type ?? 'airline',
                    sellingPrice: (float) ($item->total_amount ?? $item->total ?? 0),
                    vatAmount: 0.0,
                    providerCost: (float) ($item->net_fare ?? $item->price ?? 0),
                    providerReference: $item->provider_reference ?? 'legacy',
                );

                app(LedgerPostingService::class)->postIssuanceEntry($dto);

                $item->update(['ledger_entry_id' => 'backfilled']);
                $succeeded++;
            } catch (\Throwable $e) {
                $this->warn("Failed item {$item->id}: {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        tenancy()->end();

        $this->info("Backfill complete. Succeeded: {$succeeded}, Failed: {$failed}.");

        return self::SUCCESS;
    }
}
