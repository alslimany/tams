<?php

namespace App\Console\Commands;

use App\Models\AgencySettlement;
use App\Models\AgencyWalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinanceSettleMasterCommissionsCommand extends Command
{
    protected $signature = 'finance:settle-master-commissions
        {tenantId? : Optional buyer tenant ID. If omitted, all buyer tenants are processed}
        {--currency= : Filter by currency}
        {--dry-run : Preview only, without creating settlement records}';

    protected $description = 'Create central settlement records for outstanding default-agency commission payables.';

    public function handle(): int
    {
        $tenantId = $this->argument('tenantId');
        $currency = $this->option('currency');
        $isDryRun = (bool) $this->option('dry-run');

        /** @var Collection<int, AgencyWalletTransaction> $payables */
        $payables = AgencyWalletTransaction::query()
            ->where('type', 'commission_payable')
            ->whereNull('settlement_id')
            ->where('amount', '>', 0)
            ->whereNotNull('default_agency_tenant_id')
            ->when(
                is_string($tenantId) && $tenantId !== '',
                fn ($query) => $query->where('tenant_id', $tenantId),
            )
            ->when(
                is_string($currency) && $currency !== '',
                fn ($query) => $query->where('currency', strtoupper($currency)),
            )
            ->orderBy('tenant_id')
            ->orderBy('default_agency_tenant_id')
            ->orderBy('currency')
            ->orderBy('id')
            ->get();

        if ($payables->isEmpty()) {
            $this->info('No outstanding master commission payables found for settlement.');

            return self::SUCCESS;
        }

        $groupedPayables = $payables->groupBy(function (AgencyWalletTransaction $transaction): string {
            return implode('|', [
                $transaction->tenant_id,
                (string) $transaction->default_agency_tenant_id,
                $transaction->currency,
            ]);
        });

        $rows = [];
        $totalsByCurrency = collect();
        $settlementRecordsCreated = 0;

        foreach ($groupedPayables as $groupKey => $group) {
            [$buyerTenantId, $defaultAgencyTenantId, $groupCurrency] = explode('|', $groupKey, 3);

            /** @var Collection<int, AgencyWalletTransaction> $group */
            $settlementTotal = round((float) $group->sum('amount'), 2);
            $transactionCount = $group->count();
            $firstCreatedAt = $group->first()?->created_at;
            $lastCreatedAt = $group->last()?->created_at;
            $settlementId = null;

            if (! $isDryRun) {
                $settlementId = DB::transaction(function () use ($buyerTenantId, $defaultAgencyTenantId, $groupCurrency, $settlementTotal, $transactionCount, $firstCreatedAt, $lastCreatedAt, $group): int {
                    $settlement = AgencySettlement::create([
                        'buyer_tenant_id' => $buyerTenantId,
                        'default_agency_tenant_id' => $defaultAgencyTenantId,
                        'currency' => $groupCurrency,
                        'total_commission' => $settlementTotal,
                        'transaction_count' => $transactionCount,
                        'period_started_at' => $firstCreatedAt,
                        'period_ended_at' => $lastCreatedAt,
                        'status' => 'recorded',
                    ]);

                    AgencyWalletTransaction::query()
                        ->whereIn('id', $group->pluck('id'))
                        ->whereNull('settlement_id')
                        ->update([
                            'settlement_id' => $settlement->id,
                            'settled_at' => now(),
                            'updated_at' => now(),
                        ]);

                    return $settlement->id;
                });

                $settlementRecordsCreated++;
            }

            $rows[] = [
                $buyerTenantId,
                $defaultAgencyTenantId,
                $groupCurrency,
                number_format($settlementTotal, 2, '.', ''),
                $transactionCount,
                $settlementId ?? '-',
                $isDryRun ? 'preview' : 'recorded',
            ];

            $totalsByCurrency->put(
                $groupCurrency,
                round((float) ($totalsByCurrency->get($groupCurrency, 0) + $settlementTotal), 2),
            );
        }

        $this->table([
            'Buyer Tenant',
            'Default Agency',
            'Currency',
            'Commission Total',
            'Transactions',
            'Settlement ID',
            'Status',
        ], $rows);

        $totalsByCurrency->each(function (float $total, string $itemCurrency): void {
            $this->info("Total {$itemCurrency}: ".number_format($total, 2, '.', ''));
        });

        if ($isDryRun) {
            $this->comment('Dry run completed. No settlement records were written.');
        } else {
            $this->comment("Created {$settlementRecordsCreated} settlement record(s). Automatic cross-tenant wallet transfer is not performed.");
        }

        return self::SUCCESS;
    }
}
