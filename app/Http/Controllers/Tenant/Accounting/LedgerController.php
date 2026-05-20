<?php

namespace App\Http\Controllers\Tenant\Accounting;

use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\JournalEntry;
use Abivia\Ledger\Models\LedgerAccount;
use Abivia\Ledger\Models\SubJournal;
use App\Http\Controllers\Controller;
use App\Services\Accounting\LedgerQueryService;
use App\Services\Accounting\Reports\TrialBalanceReport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    public function __construct(
        private readonly LedgerQueryService $query,
        private readonly TrialBalanceReport $trialBalance,
    ) {}

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

        // Build uuid → code map once to avoid N+1 on sub-journal lookups.
        $subJournalMap = SubJournal::all()->pluck('code', 'subJournalUuid');

        $paginated->through(function (JournalEntry $entry) use ($subJournalMap) {
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
                'lines' => $lines,
                'orderReference' => $extra['order_id'] ?? null,
                'walletTxReference' => $extra['wallet_tx_id'] ?? null,
            ];
        });

        return Inertia::render('Accounting/Ledger/JournalEntries', [
            'entries' => $paginated,
            'filters' => $request->only(['dateFrom', 'dateTo', 'search']),
            'journalOptions' => $journalOptions,
        ]);
    }

    public function trialBalance(Request $request): Response
    {
        $asOf = $request->filled('asOf')
            ? Carbon::parse($request->string('asOf'))
            : Carbon::now();

        $rows = $this->trialBalance->generate($asOf);

        $typeMap = [
            '1' => 'asset',
            '2' => 'liability',
            '3' => 'equity',
            '4' => 'revenue',
            '5' => 'expense',
        ];

        $mapped = $rows->map(function ($row) use ($typeMap) {
            $typeKey = substr((string) $row['code'], 0, 1);

            return [
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $typeMap[$typeKey] ?? 'asset',
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

    public function chartOfAccounts(): Response
    {
        $accounts = LedgerAccount::with('names')
            ->where('category', false)
            ->orderBy('code')
            ->get()
            ->map(function (LedgerAccount $account) {
                $typeMap = [
                    '1' => 'asset',
                    '2' => 'liability',
                    '3' => 'equity',
                    '4' => 'revenue',
                    '5' => 'expense',
                ];
                $typeKey = substr((string) $account->code, 0, 1);

                return [
                    'code' => $account->code,
                    'name' => $account->names->first()?->name ?? $account->code,
                    'type' => $typeMap[$typeKey] ?? 'asset',
                    'balance' => round($this->query->accountBalanceSigned($account->code), 3),
                    'parentUuid' => $account->parentUuid,
                    'uuid' => $account->ledgerUuid,
                ];
            })->all();

        return Inertia::render('Accounting/Ledger/ChartOfAccounts', [
            'accounts' => $accounts,
        ]);
    }

    public function accountDetail(Request $request, string $code): Response
    {
        $account = LedgerAccount::where('code', $code)->with('names')->firstOrFail();
        $name = $account->names->first()?->name ?? $code;

        $typeMap = [
            '1' => 'asset',
            '2' => 'liability',
            '3' => 'equity',
            '4' => 'revenue',
            '5' => 'expense',
        ];
        $typeKey = substr((string) $code, 0, 1);

        $from = $request->string('from', Carbon::now()->startOfMonth()->toDateString())->toString();
        $to = $request->string('to', Carbon::now()->endOfMonth()->toDateString())->toString();

        // Opening balance = all debits/credits before $from
        $openingSum = JournalDetail::where('ledgerUuid', $account->ledgerUuid)
            ->whereHas('entry', fn ($q) => $q->whereDate('transDate', '<', $from))
            ->selectRaw('SUM(CAST(amount AS DECIMAL(20,3))) as net')
            ->value('net');
        $openingBalance = round((float) $openingSum, 3);

        // Lines in period
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

        // Running balance
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
                'type' => $typeMap[$typeKey] ?? 'asset',
            ],
            'period' => ['from' => $from, 'to' => $to],
            'openingBalance' => $openingBalance,
            'lines' => $linesWithBalance,
            'closingBalance' => $closingBalance,
        ]);
    }
}
