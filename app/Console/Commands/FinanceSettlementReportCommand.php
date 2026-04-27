<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class FinanceSettlementReportCommand extends Command
{
    protected $signature = 'finance:settlement-report
        {tenantId? : Optional buyer tenant ID. If omitted, all tenants are processed}
        {--days=30 : Look-back window in days}';

    protected $description = 'Generate default-agency commission settlement totals by buyer tenant, default agency, and currency.';

    public function handle(): int
    {
        $tenantId = $this->argument('tenantId');
        $days = max(1, (int) $this->option('days'));
        $startAt = now()->subDays($days);

        $tenants = Tenant::query()
            ->when(
                is_string($tenantId) && $tenantId !== '',
                fn ($query): \Illuminate\Database\Eloquent\Builder => $query->where('id', $tenantId),
            )
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->error('No tenants matched the provided criteria.');

            return self::FAILURE;
        }

        $rows = collect();

        foreach ($tenants as $tenant) {
            /** @var Collection<int, array<string, mixed>> $tenantRows */
            $tenantRows = $tenant->run(function () use ($startAt): Collection {
                $items = OrderItem::query()
                    ->where('status', 'issued')
                    ->where('agent_commission', '>', 0)
                    ->whereHas('order', function ($query) use ($startAt): void {
                        $query->whereNotNull('issued_at')
                            ->where('issued_at', '>=', $startAt);
                    })
                    ->with('order')
                    ->get();

                return $items
                    ->filter(function (OrderItem $item): bool {
                        return (string) data_get($item->item_details, 'financial_source') === 'master_agency_supply'
                            && (string) data_get($item->item_details, 'default_agency_tenant_id', '') !== '';
                    })
                    ->groupBy(function (OrderItem $item): string {
                        $defaultAgencyTenantId = (string) data_get($item->item_details, 'default_agency_tenant_id');

                        return $defaultAgencyTenantId.'|'.(string) $item->currency;
                    })
                    ->map(function (Collection $group, string $groupKey): array {
                        [$defaultAgencyTenantId, $currency] = explode('|', $groupKey, 2);

                        return [
                            'buyer_tenant_id' => (string) tenant('id'),
                            'default_agency_tenant_id' => $defaultAgencyTenantId,
                            'currency' => $currency,
                            'total_commission_payable' => round((float) $group->sum('agent_commission'), 2),
                            'item_count' => $group->count(),
                        ];
                    })
                    ->values();
            });

            $rows = $rows->merge($tenantRows->all());
        }

        if ($rows->isEmpty()) {
            $this->info("No default-agency settlement activity found in the last {$days} days.");

            return self::SUCCESS;
        }

        $totals = $rows
            ->groupBy(fn (array $row): string => $row['currency'])
            ->map(fn (Collection $group): float => round((float) $group->sum('total_commission_payable'), 2));

        $this->table([
            'Buyer Tenant',
            'Default Agency',
            'Currency',
            'Commission Payable',
            'Items',
        ], $rows->map(function (array $row): array {
            return [
                $row['buyer_tenant_id'],
                $row['default_agency_tenant_id'],
                $row['currency'],
                number_format((float) $row['total_commission_payable'], 2, '.', ''),
                $row['item_count'],
            ];
        })->all());

        foreach ($totals as $currency => $total) {
            $this->info("Total {$currency}: ".number_format($total, 2, '.', ''));
        }

        return self::SUCCESS;
    }
}
