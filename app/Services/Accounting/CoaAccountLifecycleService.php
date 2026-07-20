<?php

namespace App\Services\Accounting;

use Abivia\Ledger\Exceptions\Breaker;
use Abivia\Ledger\Logic\AccountLogic;
use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\LedgerAccount;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\CoaSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CoaAccountLifecycleService
{
    /**
     * Remove previously deleted or inactive accounts that would block reusing
     * the same code or display name.
     */
    public function purgeRemovedAccountForReuse(string $code, string $name, string $language = 'en'): void
    {
        $this->purgeByCodeIfUnused($code);
        $this->purgeUnusedAccountsWithName($name, $language, $code);
    }

    /**
     * Hard-delete an unused account and its ledger metadata.
     *
     * @throws Breaker
     */
    public function hardDeleteAccount(LedgerAccount $account): void
    {
        $logic = new AccountLogic;

        if (! $logic->canBeDeleted($account)) {
            throw Breaker::withCode(
                Breaker::RULE_VIOLATION,
                [__('Cannot delete: account or sub-accounts have transactions.')]
            );
        }

        if (! $logic->delete($account)) {
            throw Breaker::withCode(
                Breaker::RULE_VIOLATION,
                [__('Could not delete account.')]
            );
        }

        CoaSetting::query()->where('code', $account->code)->delete();

        ChartOfAccount::withTrashed()
            ->where('code', $account->code)
            ->forceDelete();
    }

    public function hasPostedTransactions(LedgerAccount $account): bool
    {
        return JournalDetail::query()
            ->where('ledgerUuid', $account->ledgerUuid)
            ->exists();
    }

    private function purgeByCodeIfUnused(string $code): void
    {
        $account = LedgerAccount::query()->where('code', $code)->first();

        if ($account === null || $this->hasPostedTransactions($account)) {
            return;
        }

        if (! $this->canPurgeAccount($account)) {
            return;
        }

        try {
            $this->hardDeleteAccount($account);
        } catch (Breaker) {
            // Leave the row in place; the create/update request will surface the error.
        }
    }

    private function purgeUnusedAccountsWithName(string $name, string $language, string $exceptCode): void
    {
        $codes = DB::table('ledger_names as ln')
            ->join('ledger_accounts as la', 'la.ledgerUuid', '=', 'ln.ownerUuid')
            ->where('ln.name', $name)
            ->where('ln.language', $language)
            ->where('la.code', '!=', $exceptCode)
            ->pluck('la.code');

        foreach ($codes as $code) {
            $account = LedgerAccount::query()->where('code', $code)->first();

            if ($account === null || $this->hasPostedTransactions($account)) {
                continue;
            }

            if (! $this->canPurgeAccount($account)) {
                continue;
            }

            try {
                $this->hardDeleteAccount($account);
            } catch (Breaker) {
                continue;
            }
        }
    }

    /**
     * Only purge accounts the user removed or explicitly deactivated.
     */
    private function canPurgeAccount(LedgerAccount $account): bool
    {
        $wasSoftDeleted = ChartOfAccount::withTrashed()
            ->where('ledgerUuid', $account->ledgerUuid)
            ->whereNotNull('deleted_at')
            ->exists();

        if ($wasSoftDeleted) {
            return true;
        }

        // During early migrations coa_settings may not exist yet. Only soft-deleted
        // accounts are safe to purge in that case — never live system accounts.
        if (! Schema::hasTable('coa_settings')) {
            return false;
        }

        $setting = CoaSetting::query()->where('code', $account->code)->first();

        if ($setting === null) {
            return true;
        }

        return ! $setting->is_active && ! $setting->is_system;
    }
}
