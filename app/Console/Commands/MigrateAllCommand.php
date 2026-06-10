<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Run landlord and tenant migrations in a single command.
 *
 * Usage:
 *   php artisan migrate:all                        # migrate landlord + all tenants
 *   php artisan migrate:all --fresh                # fresh migrate landlord + all tenants
 *   php artisan migrate:all --seed                 # migrate + seed both
 *   php artisan migrate:all --tenants=foo,bar      # migrate landlord + specific tenants only
 *   php artisan migrate:all --fresh --seed --force # full reset in production
 */
class MigrateAllCommand extends Command
{
    protected $signature = 'migrate:all
                            {--fresh : Drop all tables and re-run all migrations}
                            {--seed : Seed the database after migrations}
                            {--force : Force the operation to run in production}
                            {--tenants=* : Tenant ID(s) to migrate (defaults to all tenants)}
                            {--pretend : Dump the SQL queries that would be run}
                            {--step : Force the migrations to be run so each migration is in its own transaction}';

    protected $description = 'Run landlord migrations and tenant migrations together';

    public function handle(): int
    {
        $fresh = $this->option('fresh');
        $seed = $this->option('seed');
        $force = $this->option('force');
        $pretend = $this->option('pretend');
        $step = $this->option('step');
        $tenants = $this->option('tenants');

        // ── Landlord ──────────────────────────────────────────────────────────
        $this->components->info('Running landlord migrations…');

        $landlordCommand = $fresh ? 'migrate:fresh' : 'migrate';
        $landlordOptions = array_filter([
            '--force' => $force,
            '--seed' => $seed,
            '--pretend' => $pretend,
            '--step' => $step && ! $fresh,
        ]);

        $landlordExit = $this->call($landlordCommand, $landlordOptions);

        if ($landlordExit !== self::SUCCESS) {
            $this->components->error('Landlord migration failed. Tenant migrations skipped.');

            return self::FAILURE;
        }

        // ── Tenants ───────────────────────────────────────────────────────────
        $this->components->info('Running tenant migrations…');

        $tenantOptions = array_filter([
            '--fresh' => $fresh,
            '--seed' => $seed,
            '--force' => $force,
            '--pretend' => $pretend,
            '--tenants' => $tenants ?: null,
        ]);

        $tenantExit = $this->call('tenants:migrate', $tenantOptions);

        if ($tenantExit !== self::SUCCESS) {
            $this->components->error('Tenant migration failed.');

            return self::FAILURE;
        }

        $this->components->info('All migrations completed successfully.');

        return self::SUCCESS;
    }
}
