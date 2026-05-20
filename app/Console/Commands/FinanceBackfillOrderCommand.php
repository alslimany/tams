<?php

namespace App\Console\Commands;

use App\Actions\Finance\ApplyFinancialSourceAndCommission;
use App\Actions\Finance\InitializeTenantLedger;
use App\Actions\Finance\PostToLedger;
use App\Actions\Finance\ProcessProviderWalletTransactions;
use App\Actions\Finance\ProcessWalletTransactions;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Console\Command;
use RuntimeException;

class FinanceBackfillOrderCommand extends Command
{
    protected $signature = 'finance:backfill-order
        {identifier : Order ID or PNR locator}
        {--tenant= : Tenant ID when command is executed from landlord context}
        {--pnr : Interpret identifier as a PNR locator}
        {--skip-ledger : Skip ledger posting step}';

    protected $description = 'Backfill order financial source, commissions, wallet/provider transactions, and optional ledger posting.';

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
        try {
            $order = $this->resolveOrder();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $issuer = $this->resolveIssuer($order);
        if (! $issuer) {
            $this->error('Unable to resolve issuer user (admin/manager/owner) for wallet operations.');

            return self::FAILURE;
        }

        app(ApplyFinancialSourceAndCommission::class)->execute($order);

        $financialSources = $order->fresh('items')->items
            ->pluck('item_details.financial_source')
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->unique();

        $resolvedPaymentMethod = match (true) {
            $financialSources->contains('master_agency_supply') && $financialSources->count() === 1 => 'default_agency_supply',
            $financialSources->contains('master_agency_supply') && $financialSources->contains('own_credentials') => 'mixed_supply',
            $financialSources->contains('own_credentials') && $financialSources->count() === 1 => 'own_credentials',
            default => (string) ($order->payment_method ?? 'airline_token'),
        };

        $order->update(['payment_method' => $resolvedPaymentMethod]);

        if ($financialSources->contains('master_agency_supply')) {
            app(ProcessWalletTransactions::class)->execute($order, $issuer);
        }

        if ($financialSources->contains('own_credentials')) {
            app(ProcessProviderWalletTransactions::class)->execute($order);
        }

        if (! (bool) $this->option('skip-ledger')) {
            $order = $order->fresh('items');
            app(InitializeTenantLedger::class)->execute((string) $order->currency);
            $this->reverseOrphanedLedgerEntries($order);
            app(PostToLedger::class)->execute($order->fresh('items'), includeOwnCredentials: true);
        }

        $order = $order->fresh('items');

        $this->info('Backfill completed successfully.');
        $this->line('Order ID: '.$order->id);
        $this->line('Payment method: '.(string) $order->payment_method);
        $this->line('Items: '.(string) $order->items->count());
        $this->line('Wallet-linked items: '.(string) $order->items->filter(fn ($item): bool => $item->wallet_transaction_id !== null)->count());
        $this->line('Legacy airline-linked items: '.(string) $order->items->filter(fn ($item): bool => $item->airline_transaction_id !== null)->count());
        $this->line('Ledger-linked items: '.(string) $order->items->filter(fn ($item): bool => $item->ledger_entry_id !== null)->count());

        return self::SUCCESS;
    }

    /**
     * Find abivia journal entries that reference items in this order by the
     * "order:{id}|item:{item_id}" pattern stored in the entry's extra field,
     * but where the item's ledger_entry_id is still null (orphaned entries).
     *
     * For each orphaned entry, post a reversal so the ledger stays balanced,
     * then PostToLedger will re-post the corrected entry.
     */
    protected function reverseOrphanedLedgerEntries(Order $order): void
    {
        $ledgerService = app(LedgerPostingService::class);
        $reversed = 0;

        foreach ($order->items as $item) {
            if ($item->ledger_entry_id !== null) {
                continue;
            }

            $reference = "order:{$order->id}|item:{$item->id}";

            // Search abivia for entries whose extra JSON contains this reference.
            $orphan = $ledgerService->findOrphanedEntry($reference);

            if (! $orphan) {
                continue;
            }

            $ledgerService->postMirrorReversal($orphan, (string) $order->id);

            $reversed++;

            $this->line("  Reversed orphaned ledger entry #{$orphan->journalEntryId} for item {$item->id}");
        }

        if ($reversed > 0) {
            $this->line("Reversed {$reversed} orphaned ledger entr".($reversed === 1 ? 'y' : 'ies').'.');
        }
    }

    protected function resolveOrder(): Order
    {
        $identifier = (string) $this->argument('identifier');
        $byPnr = (bool) $this->option('pnr');

        $order = Order::query()
            ->when($byPnr, function ($query) use ($identifier): void {
                $query->where(function ($nested) use ($identifier): void {
                    $nested
                        ->where('payment_reference', $identifier)
                        ->orWhereHas('items', fn ($items) => $items->where('provider_reference', $identifier));
                });
            }, fn ($query) => $query->whereKey($identifier))
            ->latest('created_at')
            ->with('items')
            ->first();

        if (! $order) {
            throw new RuntimeException($byPnr
                ? "No order found for PNR {$identifier}."
                : "No order found with ID {$identifier}.");
        }

        return $order;
    }

    protected function resolveIssuer(Order $order): ?User
    {
        $issuer = User::query()
            ->whereIn('role', ['admin', 'manager'])
            ->orderBy('id')
            ->first();

        if ($issuer) {
            return $issuer;
        }

        if ($order->owner_type === User::class && filled($order->owner_id)) {
            return User::query()->find($order->owner_id);
        }

        return null;
    }
}
