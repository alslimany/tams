<?php

namespace App\Services\Accounting;

use Abivia\Ledger\Exceptions\Breaker;
use Abivia\Ledger\Http\Controllers\LedgerAccountController;
use Abivia\Ledger\Http\Controllers\SubJournalController;
use Abivia\Ledger\Messages\Account;
use Abivia\Ledger\Messages\Create;
use Abivia\Ledger\Messages\SubJournal as SubJournalMessage;
use Abivia\Ledger\Models\LedgerAccount;
use Abivia\Ledger\Models\SubJournal;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Date;
use RuntimeException;

class LedgerBootstrapService
{
    public function __construct(
        private readonly CoaSettingsSyncService $coaSettingsSync,
        private readonly CoaAccountLifecycleService $coaLifecycle,
    ) {}

    /**
     * Bootstrap the Chart of Accounts for the given tenant.
     *
     * Must be called inside the tenant context (i.e. after $tenant->run() or within a tenant request).
     *
     * @return array{created_root: bool, added_accounts: int, total_required_accounts: int}
     *
     * @throws Breaker
     */
    public function bootstrapForTenant(Tenant $tenant, string $currency = 'LYD'): array
    {
        $templatePath = $this->resolveTemplatePath($tenant->type ?? 'direct');

        if (! file_exists($templatePath)) {
            throw new RuntimeException("Ledger CoA template not found: {$templatePath}");
        }

        $templateData = json_decode(file_get_contents($templatePath), true, 512, JSON_THROW_ON_ERROR);

        // Allow currency override (e.g. for tests or multi-currency tenants)
        if ($currency !== 'LYD') {
            $templateData['currencies'] = [['code' => strtoupper($currency), 'decimals' => 2]];
        }

        // Extract journals before passing to Create::fromArray — abivia's RootController
        // has a bug where it accesses $journal['names'] on a SubJournal object (not array),
        // causing "Cannot use object of type SubJournal as array". We create journals
        // separately after the root is bootstrapped.
        $journalsData = $templateData['journals'] ?? [];
        unset($templateData['journals']);

        $originalDateClass = get_class(Date::now());
        Date::use(Carbon::class);

        // Reset the static root cache so multi-tenant contexts don't bleed into each other.
        LedgerAccount::resetRules();

        try {
            if (LedgerAccount::hasRoot()) {
                $this->createJournals($journalsData);

                $result = $this->addMissingAccounts($templateData);
                $this->coaSettingsSync->syncFromLedger(markSystem: true);

                return $result;
            }

            $templateData['transDate'] = Carbon::now()->toDateTimeString();

            $create = Create::fromArray($templateData);
            (new LedgerAccountController)->create($create);

            // Create sub-journals separately (abivia's RootController::initializeJournals
            // has a bug accessing SubJournal objects as arrays when journals are included
            // in the Create message).
            $this->createJournals($journalsData);

            $this->coaSettingsSync->syncFromLedger(markSystem: true);

            $accountCount = count($templateData['accounts'] ?? []);

            return [
                'created_root' => true,
                'added_accounts' => $accountCount,
                'total_required_accounts' => $accountCount,
            ];
        } finally {
            Date::use($originalDateClass);
        }
    }

    /**
     * @return array{created_root: bool, added_accounts: int, total_required_accounts: int}
     */
    private function addMissingAccounts(array $templateData): array
    {
        $existingCodes = $this->existingAccountCodes();
        $addedAccounts = 0;
        $accounts = $templateData['accounts'] ?? [];

        foreach ($accounts as $definition) {
            $code = (string) ($definition['code'] ?? '');
            $name = (string) ($definition['name'] ?? $code);

            if ($code === '') {
                continue;
            }

            $this->coaLifecycle->purgeRemovedAccountForReuse($code, $name);
            $existingCodes = $this->existingAccountCodes();

            if (in_array($code, $existingCodes, true)) {
                continue;
            }

            try {
                $accountData = $definition;

                if (isset($accountData['parent']) && is_string($accountData['parent'])) {
                    $accountData['parent'] = ['code' => (string) $accountData['parent']];
                }

                (new LedgerAccountController)->add(Account::fromArray($accountData));
                $existingCodes[] = $code;
                $addedAccounts++;
            } catch (Breaker $exception) {
                $details = implode(' ', $exception->getErrors(withMessage: true));

                throw Breaker::withCode(
                    $exception->getCode() ?: Breaker::RULE_VIOLATION,
                    ["Failed adding ledger account {$code} ({$name}): {$details}"],
                    $exception,
                );
            }
        }

        return [
            'created_root' => false,
            'added_accounts' => $addedAccounts,
            'total_required_accounts' => count($accounts),
        ];
    }

    /**
     * @return list<string>
     */
    private function existingAccountCodes(): array
    {
        return LedgerAccount::query()
            ->pluck('code')
            ->map(fn ($code): string => (string) $code)
            ->filter(fn (string $code): bool => $code !== '')
            ->values()
            ->all();
    }

    /**
     * Create sub-journals from raw template data.
     * Called separately because abivia's RootController::initializeJournals() has a bug
     * where it accesses SubJournal message objects as arrays.
     *
     * @param  array<array{name: string, code: string}>  $journalsData
     */
    private function createJournals(array $journalsData): void
    {
        $controller = new SubJournalController;
        $existingCodes = SubJournal::query()->pluck('code')->all();

        foreach ($journalsData as $journalData) {
            if (in_array($journalData['code'], $existingCodes, true)) {
                continue;
            }

            $message = SubJournalMessage::fromArray($journalData);
            $controller->add($message);
            $existingCodes[] = $journalData['code'];
        }
    }

    private function resolveTemplatePath(string $type): string
    {
        $template = match ($type) {
            'network' => 'network-agency',
            'merchant' => 'merchant-agency',
            default => 'direct-agency',
        };

        return resource_path("ledger/templates/{$template}.json");
    }
}
