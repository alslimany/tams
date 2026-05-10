<?php

namespace App\Console\Commands;

use App\Actions\Finance\InitializeTenantLedger;
use App\Actions\Finance\PostToLedger;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use Illuminate\Console\Command;

class FinanceBackfillLedgerCommand extends Command
{
    protected $signature = 'finance:backfill-ledger
        {identifier? : Optional order ID or PNR locator}
        {--tenant= : Tenant ID when command is executed from landlord context}
        {--pnr : Interpret identifier as a PNR locator}
        {--limit=200 : Maximum number of orders to process when identifier is omitted}
        {--skip-initialize : Skip ledger initialization step}';

    protected $description = 'Backfill missing ledger entries for orders that already have wallet transactions.';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        if (tenancy()->initialized) {
            return $this->handleForCurrentTenant();
        }

        if (! is_string($tenantId) || trim($tenantId) === '') {
            $this->error('No tenant context detected. Provide --tenant=<tenant-id>.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            $this->error("Tenant {$tenantId} was not found.");

            return self::FAILURE;
        }

        return (int) $tenant->run(fn (): int => $this->handleForCurrentTenant());
    }

    protected function handleForCurrentTenant(): int
    {
        $orders = $this->resolveOrders();

        if ($orders->isEmpty()) {
            $this->info('No orders found that require ledger backfill.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('skip-initialize')) {
            app(InitializeTenantLedger::class)->execute('USD');
        }

        $postedItems = 0;
        $processedOrders = 0;
        $failedOrders = 0;

        foreach ($orders as $order) {
            $beforeCount = $order->items->filter(fn ($item): bool => $item->ledger_entry_id !== null)->count();

            try {
                app(PostToLedger::class)->execute($order, includeOwnCredentials: true);
                $processedOrders++;
            } catch (\Throwable $exception) {
                report($exception);
                $failedOrders++;

                $this->warn("Failed ledger backfill for order {$order->id}: {$exception->getMessage()}");

                continue;
            }

            $afterCount = $order->fresh('items')->items->filter(fn ($item): bool => $item->ledger_entry_id !== null)->count();
            $postedItems += max(0, $afterCount - $beforeCount);
        }

        $this->info('Ledger backfill completed.');
        $this->line('Orders matched: '.(string) $orders->count());
        $this->line('Orders processed: '.(string) $processedOrders);
        $this->line('Orders failed: '.(string) $failedOrders);
        $this->line('Items linked to ledger: '.(string) $postedItems);

        return $failedOrders > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Order>
     */
    protected function resolveOrders(): \Illuminate\Support\Collection
    {
        $identifier = (string) $this->argument('identifier');
        $byPnr = (bool) $this->option('pnr');
        $limit = max(1, (int) $this->option('limit'));

        return Order::query()
            ->with('items')
            ->when($identifier !== '', function ($query) use ($identifier, $byPnr): void {
                if ($byPnr) {
                    $query->where(function ($nested) use ($identifier): void {
                        $nested
                            ->where('payment_reference', $identifier)
                            ->orWhereHas('items', fn ($items) => $items->where('provider_reference', $identifier));
                    });

                    return;
                }

                $query->where('id', $identifier);
            })
            ->whereHas('items', function ($query): void {
                $query
                    ->whereNull('ledger_entry_id')
                    ->whereNotNull('wallet_transaction_id');
            })
            ->orderBy('issued_at')
            ->orderBy('created_at')
            ->limit($identifier === '' ? $limit : 1)
            ->get();
    }
}
