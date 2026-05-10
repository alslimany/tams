<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\AirlineAccount;
use App\Models\Tenant\TenantInsuranceProviderAccount;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Console\Command;

class FinanceBackfillProviderWalletsCommand extends Command
{
    protected $signature = 'finance:backfill-provider-wallets
        {--tenant= : Tenant ID when command is executed from landlord context}
        {--type=all : Provider type to backfill: airline, insurance, or all}
        {--dry-run : Report what would be backfilled without writing wallet transactions}';

    protected $description = 'Backfill legacy airline and insurance provider account balances into Bavix provider wallets.';

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
        $type = strtolower((string) $this->option('type'));
        if (! in_array($type, ['airline', 'insurance', 'all'], true)) {
            $this->error('Invalid --type value. Use airline, insurance, or all.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $summary = [
            'processed' => 0,
            'backfilled' => 0,
            'skipped' => 0,
            'missing_provider' => 0,
            'negative_skipped' => 0,
        ];

        if ($type === 'airline' || $type === 'all') {
            $summary = $this->mergeSummary($summary, $this->backfillAirlineAccounts($dryRun));
        }

        if ($type === 'insurance' || $type === 'all') {
            $summary = $this->mergeSummary($summary, $this->backfillInsuranceAccounts($dryRun));
        }

        $this->info($dryRun ? 'Provider wallet backfill dry run completed.' : 'Provider wallet backfill completed.');
        $this->line('Processed legacy accounts: '.(string) $summary['processed']);
        $this->line('Backfilled balances: '.(string) $summary['backfilled']);
        $this->line('Skipped already backfilled or zero balances: '.(string) $summary['skipped']);
        $this->line('Skipped missing providers: '.(string) $summary['missing_provider']);
        $this->line('Skipped negative balances: '.(string) $summary['negative_skipped']);

        return self::SUCCESS;
    }

    /**
     * @return array{processed:int, backfilled:int, skipped:int, missing_provider:int, negative_skipped:int}
     */
    protected function backfillAirlineAccounts(bool $dryRun): array
    {
        $summary = $this->emptySummary();

        AirlineAccount::query()->with('provider')->each(function (AirlineAccount $account) use (&$summary, $dryRun): void {
            $summary['processed']++;

            $provider = $account->provider;
            if (! $provider) {
                $summary['missing_provider']++;

                return;
            }

            $amount = round((float) $account->balance, 2);
            if ($amount < 0) {
                $summary['negative_skipped']++;

                return;
            }

            if ($amount === 0.0 || $this->hasLegacyBackfillTransaction('airline_accounts', (string) $account->id)) {
                $summary['skipped']++;

                return;
            }

            if (! $dryRun) {
                $provider->getOrCreateCurrencyWallet((string) $account->currency)->depositFloat($amount, [
                    'type' => 'legacy_balance_backfill',
                    'legacy_table' => 'airline_accounts',
                    'legacy_id' => (string) $account->id,
                    'provider_type' => 'airline',
                    'provider_id' => $provider->id,
                    'currency' => strtoupper((string) $account->currency),
                ]);
            }

            $summary['backfilled']++;
        });

        return $summary;
    }

    /**
     * @return array{processed:int, backfilled:int, skipped:int, missing_provider:int, negative_skipped:int}
     */
    protected function backfillInsuranceAccounts(bool $dryRun): array
    {
        $summary = $this->emptySummary();

        TenantInsuranceProviderAccount::query()->with('provider')->each(function (TenantInsuranceProviderAccount $account) use (&$summary, $dryRun): void {
            $summary['processed']++;

            $provider = $account->provider;
            if (! $provider) {
                $summary['missing_provider']++;

                return;
            }

            $amount = round((float) $account->balance, 2);
            if ($amount < 0) {
                $summary['negative_skipped']++;

                return;
            }

            if ($amount === 0.0 || $this->hasLegacyBackfillTransaction('tenant_insurance_provider_accounts', (string) $account->id)) {
                $summary['skipped']++;

                return;
            }

            if (! $dryRun) {
                $provider->getOrCreateCurrencyWallet((string) $account->currency)->depositFloat($amount, [
                    'type' => 'legacy_balance_backfill',
                    'legacy_table' => 'tenant_insurance_provider_accounts',
                    'legacy_id' => (string) $account->id,
                    'provider_type' => 'insurance',
                    'provider_id' => $provider->id,
                    'currency' => strtoupper((string) $account->currency),
                ]);
            }

            $summary['backfilled']++;
        });

        return $summary;
    }

    protected function hasLegacyBackfillTransaction(string $legacyTable, string $legacyId): bool
    {
        return WalletTransaction::query()
            ->get(['meta'])
            ->contains(function (WalletTransaction $transaction) use ($legacyTable, $legacyId): bool {
                $meta = is_array($transaction->meta) ? $transaction->meta : [];

                return ($meta['type'] ?? null) === 'legacy_balance_backfill'
                    && ($meta['legacy_table'] ?? null) === $legacyTable
                    && (string) ($meta['legacy_id'] ?? '') === $legacyId;
            });
    }

    /**
     * @return array{processed:int, backfilled:int, skipped:int, missing_provider:int, negative_skipped:int}
     */
    protected function emptySummary(): array
    {
        return [
            'processed' => 0,
            'backfilled' => 0,
            'skipped' => 0,
            'missing_provider' => 0,
            'negative_skipped' => 0,
        ];
    }

    /**
     * @param  array{processed:int, backfilled:int, skipped:int, missing_provider:int, negative_skipped:int}  $base
     * @param  array{processed:int, backfilled:int, skipped:int, missing_provider:int, negative_skipped:int}  $addition
     * @return array{processed:int, backfilled:int, skipped:int, missing_provider:int, negative_skipped:int}
     */
    protected function mergeSummary(array $base, array $addition): array
    {
        foreach ($addition as $key => $value) {
            $base[$key] += $value;
        }

        return $base;
    }
}
