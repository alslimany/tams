<?php

namespace App\Console\Commands;

use App\Actions\Finance\InitializeTenantLedger;
use App\Actions\Finance\PostToLedger;
use App\Actions\Finance\ProcessWalletTransactions;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\User;
use Illuminate\Console\Command;

class InsuranceBackfillCompulsoryFinancialsCommand extends Command
{
    protected $signature = 'insurance:backfill-compulsory-financials
        {identifier? : Optional order ID or policy number}
        {--tenant= : Tenant ID when command is executed from landlord context}
        {--policy : Interpret identifier as policy number / provider reference}
        {--limit=200 : Maximum number of orders to process when identifier is omitted}
        {--strict-balance : Enforce wallet balance checks (default allows negative deduction)}
        {--skip-ledger : Skip ledger posting step}';

    protected $description = 'Backfill wallet/ledger financials for compulsory insurance orders.';

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
            $this->info('No compulsory insurance orders matched the criteria.');

            return self::SUCCESS;
        }

        $issuer = $this->resolveIssuer($orders->first());
        if (! $issuer) {
            $this->error('Unable to resolve issuer user (admin/manager/owner) for wallet operations.');

            return self::FAILURE;
        }

        $allowNegativeBalance = ! (bool) $this->option('strict-balance');
        $walletLinkedItems = 0;
        $ledgerLinkedItems = 0;
        $processedOrders = 0;
        $failedOrders = 0;

        foreach ($orders as $order) {
            $beforeWallet = $order->items->filter(fn ($item): bool => $item->wallet_transaction_id !== null)->count();
            $beforeLedger = $order->items->filter(fn ($item): bool => $item->ledger_entry_id !== null)->count();

            try {
                app(ProcessWalletTransactions::class)->execute($order, $issuer, $allowNegativeBalance);

                if (! (bool) $this->option('skip-ledger')) {
                    app(InitializeTenantLedger::class)->execute((string) $order->currency);
                    app(PostToLedger::class)->execute($order, includeOwnCredentials: false);
                }

                $processedOrders++;
            } catch (\Throwable $exception) {
                report($exception);
                $failedOrders++;

                $this->warn("Failed order {$order->id} ({$order->number}): {$exception->getMessage()}");

                continue;
            }

            $freshOrder = $order->fresh('items');

            $walletLinkedItems += max(0, $freshOrder->items->filter(fn ($item): bool => $item->wallet_transaction_id !== null)->count() - $beforeWallet);
            $ledgerLinkedItems += max(0, $freshOrder->items->filter(fn ($item): bool => $item->ledger_entry_id !== null)->count() - $beforeLedger);

            $this->line("Processed order {$freshOrder->id} ({$freshOrder->number})");
        }

        $this->info('Compulsory insurance financial backfill completed.');
        $this->line('Orders matched: '.(string) $orders->count());
        $this->line('Orders processed: '.(string) $processedOrders);
        $this->line('Orders failed: '.(string) $failedOrders);
        $this->line('New wallet-linked items: '.(string) $walletLinkedItems);
        $this->line('New ledger-linked items: '.(string) $ledgerLinkedItems);

        return $failedOrders > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Order>
     */
    protected function resolveOrders(): \Illuminate\Support\Collection
    {
        $identifier = trim((string) $this->argument('identifier'));
        $byPolicy = (bool) $this->option('policy');
        $limit = max(1, (int) $this->option('limit'));

        return Order::query()
            ->with('items')
            ->whereHas('items', function ($query): void {
                $query
                    ->where('product_type', 'insurance')
                    ->where('product_subtype', 'compulsory')
                    ->where(function ($financials): void {
                        $financials
                            ->whereNull('wallet_transaction_id')
                            ->orWhereNull('ledger_entry_id');
                    });
            })
            ->when($identifier !== '', function ($query) use ($identifier, $byPolicy): void {
                if ($byPolicy) {
                    $query->where(function ($nested) use ($identifier): void {
                        $nested
                            ->where('payment_reference', $identifier)
                            ->orWhereHas('items', fn ($items) => $items->where('provider_reference', $identifier));
                    });

                    return;
                }

                $query->where('id', $identifier);
            })
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at')
            ->limit($identifier === '' ? $limit : 1)
            ->get();
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
