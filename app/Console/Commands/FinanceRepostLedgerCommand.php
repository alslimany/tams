<?php

namespace App\Console\Commands;

use App\Actions\Finance\InitializeTenantLedger;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FinanceRepostLedgerCommand extends Command
{
    protected $signature = 'finance:repost-ledger
        {identifier? : Optional order ID or PNR locator}
        {--tenant= : Tenant ID when command is executed from landlord context}
        {--pnr : Interpret identifier as a PNR locator}
        {--limit=50 : Maximum number of orders to process when identifier is omitted}
        {--dry-run : Preview what would be reversed and re-posted without making changes}
        {--skip-initialize : Skip ledger initialization step}';

    protected $description = 'Revoke existing (potentially wrong) ledger entries and re-post corrected ones for issued order items.';

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
        $isDryRun = (bool) $this->option('dry-run');
        $orders = $this->resolveOrders();

        if ($orders->isEmpty()) {
            $this->info('No orders found with existing ledger entries to repost.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn('[DRY RUN] No changes will be made.');
        }

        if (! $isDryRun && ! (bool) $this->option('skip-initialize')) {
            app(InitializeTenantLedger::class)->execute('USD');
        }

        $reversedItems = 0;
        $repostedItems = 0;
        $failedOrders = 0;
        $processedOrders = 0;

        foreach ($orders as $order) {
            $items = $order->items->filter(fn (OrderItem $item): bool => $item->ledger_entry_id !== null);

            if ($items->isEmpty()) {
                continue;
            }

            $this->line("Order {$order->number} ({$order->id}) — {$items->count()} item(s) with ledger entries.");

            if ($isDryRun) {
                foreach ($items as $item) {
                    $this->line("  [DRY RUN] Would reverse entry #{$item->ledger_entry_id} and re-post for item {$item->id}");
                    $this->line("    net_fare={$item->net_fare}  total_tax={$item->total_tax}  commission={$item->commission_amount}  total={$item->total_amount}  type={$item->product_type}");
                }
                $processedOrders++;

                continue;
            }

            try {
                [$reversed, $reposted] = $this->repostOrder($order, $items);
                $reversedItems += $reversed;
                $repostedItems += $reposted;
                $processedOrders++;
            } catch (\Throwable $exception) {
                report($exception);
                $failedOrders++;
                $this->warn("  Failed repost for order {$order->id}: {$exception->getMessage()}");
            }
        }

        $this->info($isDryRun ? '[DRY RUN] Preview complete.' : 'Ledger repost completed.');
        $this->line('Orders matched:    '.(string) $orders->count());
        $this->line('Orders processed:  '.(string) $processedOrders);
        $this->line('Orders failed:     '.(string) $failedOrders);

        if (! $isDryRun) {
            $this->line('Entries reversed:  '.(string) $reversedItems);
            $this->line('Entries re-posted: '.(string) $repostedItems);
        }

        return $failedOrders > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Reverse existing ledger entries for all eligible items in an order,
     * then re-post corrected entries using the current PostToLedger logic.
     *
     * @param  \Illuminate\Support\Collection<int, OrderItem>  $items
     * @return array{int, int} [reversedCount, repostedCount]
     */
    protected function repostOrder(Order $order, \Illuminate\Support\Collection $items): array
    {
        $ledgerService = app(LedgerPostingService::class);
        $reversed = 0;
        $reposted = 0;

        DB::transaction(function () use ($order, $items, $ledgerService, &$reversed, &$reposted): void {
            foreach ($items as $item) {
                $originalEntryId = $item->ledger_entry_id;

                // --- Step 1: post reversal ---
                $productType = $this->normalizeProductType((string) $item->product_type);
                $sellingPrice = round((float) $item->total_amount, 3);
                $taxTotal = round((float) $item->total_tax, 3);
                $commissionAmount = round((float) $item->commission_amount, 3);
                $baseFare = round((float) $item->net_fare, 3);
                $providerCost = round($baseFare - $commissionAmount + $taxTotal, 3);

                $ledgerService->postReversalEntry(
                    originalOrderId: (string) $order->id,
                    sellingPrice: $sellingPrice,
                    productType: $productType,
                    taxTotal: $taxTotal > 0 ? $taxTotal : null,
                    commissionAmount: $commissionAmount > 0 ? $commissionAmount : null,
                    providerCost: $providerCost > 0 ? $providerCost : null,
                );

                $reversed++;

                $this->line("  Reversed entry #{$originalEntryId} for item {$item->id}");

                // --- Step 2: re-post corrected entry ---
                $newEntry = $ledgerService->post(
                    journal: $ledgerService->resolveJournal($productType),
                    description: "Sale of {$item->product_type} for order {$order->number} [repost]",
                    reference: "order:{$order->id}|item:{$item->id}",
                    clearing: true,
                    details: $this->buildDetails($item, $ledgerService),
                );

                $item->update(['ledger_entry_id' => $newEntry->journalEntryId]);
                $reposted++;

                $this->line("  Re-posted new entry #{$newEntry->journalEntryId} for item {$item->id}");
            }
        });

        return [$reversed, $reposted];
    }

    /**
     * Build corrected abivia detail lines — mirrors PostToLedger::buildDetails().
     *
     * @return array<int, array{code: string, debit?: string, credit?: string}>
     */
    protected function buildDetails(OrderItem $item, LedgerPostingService $ledgerService): array
    {
        $productType = $this->normalizeProductType((string) $item->product_type);
        $baseFare = round((float) $item->net_fare, 3);
        $taxTotal = round((float) $item->total_tax, 3);
        $sellingPrice = round((float) $item->total_amount, 3);
        $commissionAmount = round($this->resolveCommissionAmount($item), 3);
        $providerCost = round($baseFare - $commissionAmount + $taxTotal, 3);
        $revenueAmount = round($baseFare - $commissionAmount, 3);

        $revenueAccount = $ledgerService->revenueAccount($productType);
        $costAccount = $ledgerService->costAccount($productType);
        $providerWalletAccount = $ledgerService->providerWalletAccount($productType);

        $details = [];

        if ($sellingPrice > 0) {
            $details[] = ['code' => '1310', 'debit' => (string) $sellingPrice];
        }

        if ($revenueAmount > 0) {
            $details[] = ['code' => $revenueAccount, 'credit' => (string) $revenueAmount];
        }

        if ($commissionAmount > 0) {
            $details[] = ['code' => '4500', 'credit' => (string) $commissionAmount];
        }

        if ($taxTotal > 0) {
            $details[] = ['code' => '2410', 'credit' => (string) $taxTotal];
        }

        if ($providerCost > 0) {
            $details[] = ['code' => $costAccount, 'debit' => (string) $providerCost];
        }

        if ($providerCost > 0) {
            $details[] = ['code' => $providerWalletAccount, 'credit' => (string) $providerCost];
        }

        return $details;
    }

    protected function resolveCommissionAmount(OrderItem $item): float
    {
        $financialSource = (string) data_get($item->item_details, 'financial_source', '');

        if ($financialSource === 'master_agency_supply') {
            return (float) ($item->agent_commission ?? 0);
        }

        return (float) ($item->commission_amount ?? 0);
    }

    protected function normalizeProductType(string $productType): string
    {
        return match ($productType) {
            'flight', 'ticket' => 'airline',
            default => $productType,
        };
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
            ->whereHas('items', fn ($query) => $query->whereNotNull('ledger_entry_id'))
            ->orderBy('issued_at')
            ->orderBy('created_at')
            ->limit($identifier === '' ? $limit : 1)
            ->get();
    }
}
