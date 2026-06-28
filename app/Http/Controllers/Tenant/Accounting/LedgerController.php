<?php

namespace App\Http\Controllers\Tenant\Accounting;

use Abivia\Ledger\Http\Controllers\JournalEntryController;
use Abivia\Ledger\Http\Controllers\LedgerAccountController;
use Abivia\Ledger\Messages\Account;
use Abivia\Ledger\Messages\Entry;
use Abivia\Ledger\Messages\Message;
use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\JournalEntry;
use Abivia\Ledger\Models\LedgerAccount;
use Abivia\Ledger\Models\SubJournal;
use App\Http\Controllers\Controller;
use App\Models\Tenant\ChartOfAccount;
use App\Services\Accounting\LedgerQueryService;
use App\Services\Accounting\Reports\TrialBalanceReport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    /**
     * Maps the first digit of an account code to a financial statement type.
     *
     * @var array<string, string>
     */
    private const TYPE_MAP = [
        '1' => 'asset',
        '2' => 'liability',
        '3' => 'equity',
        '4' => 'revenue',
        '5' => 'expense',
        '6' => 'expense',
        '7' => 'asset',
    ];

    /**
     * Account types that increase with a debit (assets, expenses).
     *
     * @var list<string>
     */
    private const DEBIT_TYPES = ['asset', 'expense'];

    public function __construct(
        private readonly LedgerQueryService $query,
        private readonly TrialBalanceReport $trialBalance,
    ) {}

    // ─── Journal Entries ───────────────────────────────────────────────────

    public function journalEntries(Request $request): Response
    {
        $journalOptions = [
            ['value' => 'AIR', 'label' => 'Airline'],
            ['value' => 'HTL', 'label' => 'Hotel'],
            ['value' => 'INS', 'label' => 'Insurance'],
            ['value' => 'ESM', 'label' => 'eSIM'],
            ['value' => 'STL', 'label' => 'Settlement'],
            ['value' => 'GEN', 'label' => 'General'],
        ];

        $query = JournalEntry::with(['details.account'])
            ->orderByDesc('transDate');

        if ($request->filled('dateFrom')) {
            $query->whereDate('transDate', '>=', $request->string('dateFrom'));
        }
        if ($request->filled('dateTo')) {
            $query->whereDate('transDate', '<=', $request->string('dateTo'));
        }
        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('journalReferenceUuid', 'like', "%{$search}%");
            });
        }

        $paginated = $query->paginate(25);

        $subJournalMap = SubJournal::all()->pluck('code', 'subJournalUuid');

        $paginated->through(function (JournalEntry $entry) use ($subJournalMap) {
            return $this->formatJournalEntry($entry, $subJournalMap);
        });

        $accounts = LedgerAccount::with('names')
            ->orderBy('code')
            ->get()
            ->map(function (LedgerAccount $account) {
                return [
                    'code' => $account->code,
                    'name' => $account->names->first()?->name ?? $account->code,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Accounting/Ledger/JournalEntries', [
            'entries' => $paginated,
            'filters' => $request->only(['dateFrom', 'dateTo', 'search']),
            'journalOptions' => $journalOptions,
            'accounts' => $accounts,
        ]);
    }

    public function showJournalEntry(int $id): JsonResponse
    {
        $entry = JournalEntry::with(['details.account.names'])->findOrFail($id);
        $subJournalMap = SubJournal::all()->pluck('code', 'subJournalUuid');

        return response()->json($this->formatJournalEntry($entry, $subJournalMap));
    }

    public function storeJournalEntry(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'transDate' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'journal' => ['required', 'string', 'in:GEN,AIR,HTL,INS,ESM,STL'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.accountCode' => ['required', 'string', 'exists:ledger_accounts,code'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $totalDebit = round(collect($validated['lines'])->sum('debit'), 3);
        $totalCredit = round(collect($validated['lines'])->sum('credit'), 3);

        if (abs($totalDebit - $totalCredit) > 0.001) {
            return back()->with('error', 'Debits and credits must balance.')->withInput();
        }

        $originalDateClass = get_class(Date::now());
        Date::use(Carbon::class);

        try {
            $message = Entry::fromArray([
                'journal' => $validated['journal'],
                'description' => $validated['description'],
                'transDate' => Carbon::parse($validated['transDate'])->toDateTimeString(),
                'extra' => json_encode(['source' => 'manual'], JSON_THROW_ON_ERROR),
                'details' => collect($validated['lines'])->map(fn (array $line): array => [
                    'code' => $line['accountCode'],
                    'debit' => ! empty($line['debit']) ? (string) $line['debit'] : null,
                    'credit' => ! empty($line['credit']) ? (string) $line['credit'] : null,
                ])->all(),
            ]);

            (new JournalEntryController)->add($message);
        } catch (\Abivia\Ledger\Exceptions\Breaker $e) {
            return back()
                ->with('error', 'Could not post entry: '.implode(' ', $e->getErrors()))
                ->withInput();
        } finally {
            Date::use($originalDateClass);
        }

        return back()->with('success', 'Journal entry created successfully.');
    }

    public function updateJournalEntry(Request $request, int $id): RedirectResponse
    {
        $entry = JournalEntry::findOrFail($id);

        if (! $this->isManualEntry($entry)) {
            return back()->with('error', 'System-generated journal entries cannot be edited. Post an adjusting or reversal entry instead.');
        }

        if ($entry->locked) {
            return back()->with('error', 'This journal entry is locked and cannot be edited.');
        }

        $validated = $request->validate([
            'transDate' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.accountCode' => ['required', 'string', 'exists:ledger_accounts,code'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $totalDebit = round(collect($validated['lines'])->sum('debit'), 3);
        $totalCredit = round(collect($validated['lines'])->sum('credit'), 3);

        if (abs($totalDebit - $totalCredit) > 0.001) {
            return back()->with('error', 'Debits and credits must balance.')->withInput();
        }

        $originalDateClass = get_class(Date::now());
        Date::use(Carbon::class);

        try {
            $message = Entry::fromArray([
                'id' => $entry->journalEntryId,
                'revision' => $entry->revisionHash,
                'description' => $validated['description'],
                'transDate' => Carbon::parse($validated['transDate'])->toDateTimeString(),
                'extra' => json_encode(['source' => 'manual'], JSON_THROW_ON_ERROR),
                'details' => collect($validated['lines'])->map(fn (array $line): array => [
                    'code' => $line['accountCode'],
                    'debit' => ! empty($line['debit']) ? (string) $line['debit'] : null,
                    'credit' => ! empty($line['credit']) ? (string) $line['credit'] : null,
                ])->all(),
            ], Message::OP_UPDATE);

            (new JournalEntryController)->update($message);
        } catch (\Abivia\Ledger\Exceptions\Breaker $e) {
            return back()
                ->with('error', 'Could not update entry: '.implode(' ', $e->getErrors()))
                ->withInput();
        } finally {
            Date::use($originalDateClass);
        }

        return back()->with('success', 'Journal entry updated successfully.');
    }

    public function destroyJournalEntry(int $id): RedirectResponse
    {
        $entry = JournalEntry::findOrFail($id);

        if (! $this->isManualEntry($entry)) {
            return back()->with('error', 'System-generated journal entries cannot be deleted. Post a reversal entry instead.');
        }

        if ($entry->locked) {
            return back()->with('error', 'This journal entry is locked and cannot be deleted.');
        }

        $originalDateClass = get_class(Date::now());
        Date::use(Carbon::class);

        try {
            $message = Entry::fromArray([
                'id' => $entry->journalEntryId,
                'revision' => $entry->revisionHash,
            ], Message::OP_DELETE);

            (new JournalEntryController)->delete($message);
        } catch (\Abivia\Ledger\Exceptions\Breaker $e) {
            return back()
                ->with('error', 'Could not delete entry: '.implode(' ', $e->getErrors()));
        } finally {
            Date::use($originalDateClass);
        }

        return back()->with('success', 'Journal entry deleted successfully.');
    }

    /**
     * Determine whether a journal entry was created manually by a user
     * (as opposed to being posted automatically by the system from an
     * order, wallet transaction, settlement, or cancellation).
     */
    private function isManualEntry(JournalEntry $entry): bool
    {
        $extra = is_string($entry->extra)
            ? json_decode($entry->extra, true)
            : ($entry->extra ?? []);

        return is_array($extra) && ($extra['source'] ?? null) === 'manual';
    }

    // ─── Trial Balance ─────────────────────────────────────────────────────

    public function trialBalance(Request $request): Response
    {
        $asOf = $request->filled('asOf')
            ? Carbon::parse($request->string('asOf'))
            : Carbon::now();

        $rows = $this->trialBalance->generate($asOf);

        $mapped = $rows->map(function ($row) {
            $typeKey = substr((string) $row['code'], 0, 1);

            return [
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => self::TYPE_MAP[$typeKey] ?? 'asset',
                'debit' => round((float) $row['debit'], 3),
                'credit' => round((float) $row['credit'], 3),
                'netBalance' => round((float) $row['net'], 3),
            ];
        })->values()->all();

        $totalDebit = round(collect($mapped)->sum('debit'), 3);
        $totalCredit = round(collect($mapped)->sum('credit'), 3);

        return Inertia::render('Accounting/Ledger/TrialBalance', [
            'period' => ['asOf' => $asOf->toDateString()],
            'rows' => $mapped,
            'totals' => ['debit' => $totalDebit, 'credit' => $totalCredit],
            'isBalanced' => abs($totalDebit - $totalCredit) < 0.01,
        ]);
    }

    // ─── Chart of Accounts ─────────────────────────────────────────────────

    public function chartOfAccounts(Request $request): Response
    {
        $view = $request->string('view', 'list')->toString();

        $accounts = ChartOfAccount::with('names')
            ->where('category', false)
            ->orderBy('code')
            ->get()
            ->map(function (LedgerAccount $account) {
                $typeKey = substr((string) $account->code, 0, 1);

                return [
                    'code' => $account->code,
                    'name' => $account->names->first()?->name ?? $account->code,
                    'type' => self::TYPE_MAP[$typeKey] ?? 'asset',
                    'balance' => round($this->query->accountBalanceSigned($account->code), 3),
                    'parentUuid' => $account->parentUuid,
                    'uuid' => $account->ledgerUuid,
                ];
            })->all();

        return Inertia::render('Accounting/Ledger/ChartOfAccounts', [
            'accounts' => $accounts,
            'view' => $view,
        ]);
    }

    public function storeChartOfAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:ledger_accounts,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:asset,liability,equity,revenue,expense'],
            'parent' => ['nullable', 'string', 'exists:ledger_accounts,code'],
        ]);

        $isDebit = in_array($validated['type'], self::DEBIT_TYPES, true);

        $accountData = [
            'code' => $validated['code'],
            'names' => [['name' => $validated['name'], 'language' => 'en']],
            'debit' => $isDebit,
            'credit' => ! $isDebit,
        ];

        if (! empty($validated['parent'])) {
            $accountData['parent'] = ['code' => $validated['parent']];
        }

        $originalDateClass = get_class(Date::now());
        Date::use(Carbon::class);

        try {
            (new LedgerAccountController)->add(Account::fromArray($accountData));
        } catch (\Abivia\Ledger\Exceptions\Breaker $e) {
            return back()
                ->with('error', 'Could not create account: '.implode(' ', $e->getErrors()))
                ->withInput();
        } finally {
            Date::use($originalDateClass);
        }

        return back()->with('success', 'Account created successfully.');
    }

    public function updateChartOfAccount(Request $request, string $code): RedirectResponse
    {
        $account = ChartOfAccount::where('code', $code)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        // Update or create the English name
        $nameRecord = $account->names()->where('language', 'en')->first();

        if ($nameRecord) {
            $nameRecord->update(['name' => $validated['name']]);
        } else {
            $account->names()->create([
                'name' => $validated['name'],
                'language' => 'en',
            ]);
        }

        return back()->with('success', 'Account updated successfully.');
    }

    public function destroyChartOfAccount(string $code): RedirectResponse
    {
        $account = ChartOfAccount::where('code', $code)->firstOrFail();

        $hasTransactions = JournalDetail::where('ledgerUuid', $account->ledgerUuid)->exists();

        if ($hasTransactions) {
            return back()->with('error', 'Cannot delete an account that has posted transactions.');
        }

        $hasChildren = LedgerAccount::where('parentUuid', $account->ledgerUuid)->exists();

        if ($hasChildren) {
            return back()->with('error', 'Cannot delete an account that has sub-accounts. Delete or reassign the sub-accounts first.');
        }

        $account->delete();

        return back()->with('success', 'Account deleted successfully.');
    }

    // ─── Account Detail ────────────────────────────────────────────────────

    public function accountDetail(Request $request, string $code): Response
    {
        $account = LedgerAccount::where('code', $code)->with('names')->firstOrFail();
        $name = $account->names->first()?->name ?? $code;

        $typeKey = substr((string) $code, 0, 1);

        $from = $request->string('from', Carbon::now()->startOfMonth()->toDateString())->toString();
        $to = $request->string('to', Carbon::now()->endOfMonth()->toDateString())->toString();

        $openingSum = JournalDetail::where('ledgerUuid', $account->ledgerUuid)
            ->whereHas('entry', fn ($q) => $q->whereDate('transDate', '<', $from))
            ->selectRaw('SUM(CAST(amount AS DECIMAL(20,3))) as net')
            ->value('net');
        $openingBalance = round((float) $openingSum, 3);

        $subJournalMap = SubJournal::all()->pluck('code', 'subJournalUuid');

        $lines = JournalDetail::where('ledgerUuid', $account->ledgerUuid)
            ->whereHas('entry', fn ($q) => $q->whereDate('transDate', '>=', $from)->whereDate('transDate', '<=', $to))
            ->with('entry')
            ->orderBy('journalEntryId')
            ->get()
            ->map(function (JournalDetail $detail) use ($subJournalMap) {
                $amount = (float) $detail->amount;
                $entry = $detail->entry;
                $extra = is_string($entry->extra) ? json_decode($entry->extra, true) : ($entry->extra ?? []);

                return [
                    'date' => $entry->transDate->toDateString(),
                    'entryDescription' => $entry->description ?? '',
                    'journal' => $subJournalMap[$entry->subJournalUuid] ?? ($extra['journal'] ?? 'GEN'),
                    'debit' => $amount < 0 ? abs($amount) : null,
                    'credit' => $amount > 0 ? $amount : null,
                    'entryReference' => (string) $entry->journalEntryId,
                ];
            });

        $running = $openingBalance;
        $linesWithBalance = $lines->map(function ($line) use (&$running) {
            $running += ($line['credit'] ?? 0) - ($line['debit'] ?? 0);

            return array_merge($line, ['runningBalance' => round($running, 3)]);
        })->all();

        $closingBalance = round($running, 3);

        return Inertia::render('Accounting/Ledger/AccountDetail', [
            'account' => [
                'code' => $code,
                'name' => $name,
                'type' => self::TYPE_MAP[$typeKey] ?? 'asset',
            ],
            'period' => ['from' => $from, 'to' => $to],
            'openingBalance' => $openingBalance,
            'lines' => $linesWithBalance,
            'closingBalance' => $closingBalance,
        ]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /** @param  \Illuminate\Support\Collection<string, string>  $subJournalMap */
    private function formatJournalEntry(JournalEntry $entry, $subJournalMap): array
    {
        $lines = $entry->details->map(function (JournalDetail $detail) {
            $amount = (float) $detail->amount;
            $code = $detail->account?->code ?? '?';
            $name = $detail->account?->names->first()?->name ?? $code;

            return [
                'accountCode' => $code,
                'accountName' => $name,
                'debit' => $amount < 0 ? abs($amount) : null,
                'credit' => $amount > 0 ? $amount : null,
            ];
        })->all();

        $totalDebit = collect($lines)->sum('debit');
        $totalCredit = collect($lines)->sum('credit');

        $extra = is_string($entry->extra) ? json_decode($entry->extra, true) : ($entry->extra ?? []);
        $journal = $subJournalMap[$entry->subJournalUuid] ?? ($extra['journal'] ?? 'GEN');

        return [
            'id' => $entry->journalEntryId,
            'date' => $entry->transDate->toDateString(),
            'description' => $entry->description ?? '',
            'journal' => $journal,
            'reference' => $entry->journalReferenceUuid ?? '',
            'totalDebit' => round($totalDebit, 3),
            'totalCredit' => round($totalCredit, 3),
            'isBalanced' => abs($totalDebit - $totalCredit) < 0.001,
            'isManual' => is_array($extra) && ($extra['source'] ?? null) === 'manual',
            'isLocked' => (bool) $entry->locked,
            'lines' => $lines,
            'orderReference' => $extra['order_id'] ?? null,
            'walletTxReference' => $extra['wallet_tx_id'] ?? null,
        ];
    }
}
