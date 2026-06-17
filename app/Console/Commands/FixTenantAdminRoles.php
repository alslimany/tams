<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;

class FixTenantAdminRoles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:fix-admins';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Promote the first user of each tenant to admin role';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Tenant::all()->each(function (Tenant $tenant) {
            $this->info("Processing tenant: {$tenant->id}");

            $tenant->run(function () {
                $firstUser = User::orderBy('created_at', 'asc')->first();

                if ($firstUser) {
                    $firstUser->update(['role' => 'admin', 'is_active' => true]);
                    $this->line("  Promoted user: {$firstUser->email} to admin");
                } else {
                    $this->warn('  No users found for this tenant.');
                }
            });
        });

        $this->info('Done!');
    }
}
