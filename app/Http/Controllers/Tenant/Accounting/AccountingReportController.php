<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\Reports\BalanceSheetService;
use App\Services\Accounting\Reports\GeneralLedgerService;
use App\Services\Accounting\Reports\GrossMarginReport;
use App\Services\Accounting\Reports\IncomeStatementService;
use App\Services\Accounting\Reports\RevenueByProductReport;
use App\Services\Accounting\Reports\VATSummaryReport;
use App\Services\Accounting\WalletLedgerReconciliationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingReportController extends Controller
{
    public function __construct(
        private readonly RevenueByProductReport $revenueReport,
        private readonly GrossMarginReport $grossMarginReport,
        private readonly VATSummaryReport $vatReport,
        private readonly WalletLedgerReconciliationService $reconciliation,
        private readonly GeneralLedgerService $generalLedger,
        private readonly BalanceSheetService $balanceSheet,
        private readonly IncomeStatementService $incomeStatement,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/Reports/Index');
    }

    public function generalLedger(Request $request): Response
    {
        $from = $request->string('from', Carbon::now()->startOfMonth()->toDateString())->toString();
        $to = $request->string('to', Carbon::now()->endOfMonth()->toDateString())->toString();
        $accountCode = $request->string('account')->toString();

        $accounts = $this->generalLedger->generate(
            $from,
            $to,
            $accountCode !== '' ? [$accountCode] : null,
        );

        $accountOptions = \Abivia\Ledger\Models\LedgerAccount::with('names')
            ->where('category', false)
            ->where('code', '!=', '')
            ->orderBy('code')
            ->get()
            ->map(fn ($account) => [
                'code' => $account->code,
                'name' => $account->names->first()?->name ?? $account->code,
            ])
            ->values()
            ->all();

        return Inertia::render('Accounting/Reports/GeneralLedger', [
            'period' => ['from' => $from, 'to' => $to],
            'accounts' => $accounts,
            'accountOptions' => $accountOptions,
            'selectedAccount' => $accountCode !== '' ? $accountCode : null,
        ]);
    }

    public function balanceSheet(Request $request): Response
    {
        $asAt = $request->string('asOf', Carbon::now()->toDateString())->toString();

        return Inertia::render('Accounting/Reports/BalanceSheet', $this->balanceSheet->generate($asAt));
    }

    public function incomeStatement(Request $request): Response
    {
        $from = $request->string('from', Carbon::now()->startOfMonth()->toDateString())->toString();
        $to = $request->string('to', Carbon::now()->endOfMonth()->toDateString())->toString();

        return Inertia::render('Accounting/Reports/IncomeStatement', $this->incomeStatement->generate($from, $to));
    }

    public function revenue(): Response
    {
        $byProduct = $this->revenueReport->generate();

        $rows = collect($byProduct)->map(fn ($revenue, $product) => [
            'product' => $product,
            'revenue' => $revenue,
            'vatCollected' => 0.0, // VAT is aggregate — not split per product in current ledger
            'revenueNet' => $revenue,
            'orderCount' => 0,
        ])->values()->all();

        $totalRevenue = array_sum($byProduct);

        return Inertia::render('Accounting/Reports/Revenue', [
            'period' => ['from' => null, 'to' => null],
            'byProduct' => $rows,
            'totalRevenue' => round($totalRevenue, 3),
            'totalVat' => 0.0,
            'totalNet' => round($totalRevenue, 3),
            'trend' => [],
        ]);
    }

    public function grossMargin(): Response
    {
        $data = $this->grossMarginReport->generate();

        $rows = collect($data)->map(fn ($row, $product) => [
            'product' => $product,
            'revenue' => $row['revenue'],
            'cost' => $row['cost'],
            'margin' => $row['gross_profit'],
            'marginPct' => $row['margin_pct'],
        ])->values()->all();

        $totals = [
            'revenue' => collect($rows)->sum('revenue'),
            'cost' => collect($rows)->sum('cost'),
            'margin' => collect($rows)->sum('margin'),
            'marginPct' => collect($rows)->sum('revenue') > 0
                ? round((collect($rows)->sum('margin') / collect($rows)->sum('revenue')) * 100, 2)
                : 0.0,
        ];

        return Inertia::render('Accounting/Reports/GrossMargin', [
            'period' => ['from' => null, 'to' => null],
            'rows' => $rows,
            'totals' => $totals,
            'trend' => [],
        ]);
    }

    public function vat(): Response
    {
        $data = $this->vatReport->generate();

        return Inertia::render('Accounting/Reports/VATSummary', [
            'period' => ['from' => null, 'to' => null],
            'rows' => [],
            'totalVatCollected' => $data['collected'],
            'totalVatReversed' => $data['reversed'],
            'netPayable' => $data['net_payable'],
            'totalGross' => 0.0,
        ]);
    }

    public function reconciliation(): Response
    {
        $results = $this->reconciliation->reconcile();

        $accountNames = [
            '1110' => 'Operating Wallet',
            '1120' => 'Merchant Wallet',
            '1210' => 'Airline Provider Wallet',
            '1220' => 'Hotel Provider Wallet',
            '1230' => 'Insurance Provider Wallet',
            '1240' => 'eSIM Provider Wallet',
        ];

        $mapped = collect($results)->map(fn ($row, $code) => [
            'walletName' => $accountNames[$code] ?? $code,
            'walletSlug' => strtolower(str_replace(' ', '-', $accountNames[$code] ?? $code)),
            'ledgerAccount' => $code,
            'walletBalance' => $row['wallet_balance'],
            'ledgerBalance' => $row['ledger_balance'],
            'difference' => $row['difference'],
            'status' => strtolower($row['status']),
        ])->values()->all();

        $overallStatus = collect($mapped)->contains(fn ($r) => $r['status'] === 'mismatch')
            ? 'has_mismatches'
            : 'all_matched';

        return Inertia::render('Accounting/Reports/Reconciliation', [
            'lastRunAt' => now()->toIso8601String(),
            'results' => $mapped,
            'overallStatus' => $overallStatus,
        ]);
    }
}
