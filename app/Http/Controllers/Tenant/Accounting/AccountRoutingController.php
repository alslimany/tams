<?php

namespace App\Http\Controllers\Tenant\Accounting;

use Abivia\Ledger\Models\LedgerAccount;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AccountRouting;
use App\Models\Tenant\CoaSetting;
use App\Services\Accounting\AccountRoutingDefaults;
use App\Services\Accounting\AccountRoutingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountRoutingController extends Controller
{
    public function __construct(
        private readonly AccountRoutingDefaults $defaults,
        private readonly AccountRoutingService $routingService,
    ) {}

    public function index(): Response
    {
        $routings = AccountRouting::query()
            ->orderBy('event_category')
            ->orderBy('event_type')
            ->get()
            ->map(fn (AccountRouting $routing) => [
                'id' => $routing->id,
                'eventType' => $routing->event_type,
                'eventCategory' => $routing->event_category,
                'debitAccount' => $routing->debit_account,
                'creditAccount' => $routing->credit_account,
                'description' => $routing->description,
                'isSystem' => $routing->is_system,
                'isActive' => $routing->is_active,
            ])
            ->values()
            ->all();

        $inactiveCodes = CoaSetting::query()->where('is_active', false)->pluck('code')->flip();

        $accounts = LedgerAccount::with('names')
            ->where('category', false)
            ->where('code', '!=', '')
            ->orderBy('code')
            ->get()
            ->reject(fn (LedgerAccount $account) => isset($inactiveCodes[$account->code]))
            ->map(fn (LedgerAccount $account) => [
                'code' => $account->code,
                'name' => $account->names->first()?->name ?? $account->code,
            ])
            ->values()
            ->all();

        return Inertia::render('Accounting/Settings/AccountRouting', [
            'routings' => $routings,
            'accounts' => $accounts,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'routings' => ['required', 'array', 'min:1'],
            'routings.*.id' => ['required', 'integer', 'exists:account_routing,id'],
            'routings.*.debit_account' => ['nullable', 'string', 'exists:ledger_accounts,code'],
            'routings.*.credit_account' => ['nullable', 'string', 'exists:ledger_accounts,code'],
        ]);

        foreach ($validated['routings'] as $row) {
            $routing = AccountRouting::findOrFail($row['id']);

            $routing->update([
                // A side that is unused for this event stays null — only configured sides change.
                'debit_account' => $routing->debit_account !== null ? ($row['debit_account'] ?? $routing->debit_account) : null,
                'credit_account' => $routing->credit_account !== null ? ($row['credit_account'] ?? $routing->credit_account) : null,
            ]);
        }

        $this->routingService->clearCache();

        return back()->with('success', 'Account routing updated successfully.');
    }

    public function reset(): RedirectResponse
    {
        $this->defaults->seed(force: true);
        $this->routingService->clearCache();

        return back()->with('success', 'Account routing has been reset to defaults.');
    }
}
