<?php

namespace App\Jobs;

use Abivia\Ledger\Models\LedgerAccount;
use App\Models\Tenant;
use App\Services\Accounting\LedgerBootstrapService;
use App\Services\Wallet\WalletProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class BootstrapTenantLedgerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Tenant $tenant) {}

    public function handle(LedgerBootstrapService $ledger, WalletProvisioningService $wallets): void
    {
        // Reset the static LedgerAccount root cache before bootstrapping so that
        // a previous tenant's account tree does not bleed into this tenant's context.
        LedgerAccount::resetRules();

        $this->tenant->run(function () use ($ledger, $wallets) {
            $ledger->bootstrapForTenant($this->tenant);
            $wallets->provisionForTenant($this->tenant);
        });

        // Clear the static root after run() ends tenancy so it doesn't persist
        // in memory across subsequent tenant bootstraps or test cases.
        LedgerAccount::resetRules();
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
