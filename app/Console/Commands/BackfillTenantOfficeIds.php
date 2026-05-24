<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\OfficeIdGenerator;
use Illuminate\Console\Command;

class BackfillTenantOfficeIds extends Command
{
    protected $signature = 'tenants:backfill-office-ids
                            {--dry-run : Show what would change without writing}';

    protected $description = 'Generate and assign Office IDs to tenants that do not have one yet';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $generator = new OfficeIdGenerator;

        $tenants = Tenant::whereNull('office_id')->get();

        if ($tenants->isEmpty()) {
            $this->info('All tenants already have Office IDs.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $cityIata = $tenant->city_iata ?? OfficeIdGenerator::DEFAULT_CITY_IATA;
            $officeId = $generator->generate($cityIata, $tenant->company_name);

            $this->line(sprintf(
                '  Tenant %-45s → %s%s',
                "{$tenant->id} ({$tenant->company_name})",
                $officeId,
                $dryRun ? ' [dry-run]' : '',
            ));

            if (! $dryRun) {
                // Update office_id and city_iata on the tenant record.
                // We intentionally do NOT rename the tenant id/path for existing tenants
                // to avoid breaking SQLite file paths and existing domain records.
                $tenant->update([
                    'office_id' => $officeId,
                    'city_iata' => $cityIata,
                ]);
            }
        }

        $this->info($dryRun ? 'Dry-run complete — no changes written.' : 'Done.');

        return self::SUCCESS;
    }
}
