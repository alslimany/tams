<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\TenantProvider;
use App\Services\Accounting\LedgerQueryService;
use App\Services\Accounting\Reports\GrossMarginReport;
use App\Services\Accounting\Reports\RevenueByProductReport;
use App\Services\Accounting\Reports\VATSummaryReport;
use App\Services\Accounting\WalletLedgerReconciliationService;
use Bavix\Wallet\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingDashboardController extends Controller
{
    public function __construct(
        private readonly LedgerQueryService $query,
        private readonly RevenueByProductReport $revenueReport,
        private readonly GrossMarginReport $marginReport,
        private readonly VATSummaryReport $vatReport,
        private readonly WalletLedgerReconciliationService $reconciliation,
    ) {}

    public function index(Request $request): Response
    {
        $from = $request->string('from', Carbon::now()->startOfMonth()->toDateString())->toString();
        $to = $request->string('to', Carbon::now()->endOfMonth()->toDateString())->toString();

        // Wallet balances
        $operatingWallet = Wallet::where('slug', 'operating')->first();
        $operatingBalance = $operatingWallet
            ? (float) $operatingWallet->balance / (10 ** $operatingWallet->decimal_places)
            : 0.0;

        $merchantWallet = Wallet::where('slug', 'merchant')->first();
        $merchantBalance = $merchantWallet
            ? (float) $merchantWallet->balance / (10 ** $merchantWallet->decimal_places)
            : null;

        // Provider wallets
        $providerWallets = TenantProvider::all()->flatMap(function (TenantProvider $provider) {
            return $provider->wallets->map(fn ($w) => [
                'key' => $provider->id.'_'.$w->slug,
                'name' => $w->name,
                'slug' => $w->slug,
                'balance' => (float) $w->balance / (10 ** $w->decimal_places),
                'currency' => 'LYD',
            ]);
        })->values()->all();

        // Revenue
        $revenueByProduct = $this->revenueReport->generate();
        $totalRevenue = array_sum($revenueByProduct);

        // Gross margin
        $marginData = $this->marginReport->generate();
        $totalCost = array_sum(array_column($marginData, 'cost'));
        $grossMargin = round($totalRevenue - $totalCost, 3);
        $grossMarginPct = $totalRevenue > 0 ? round(($grossMargin / $totalRevenue) * 100, 2) : 0.0;

        // VAT
        $vat = $this->vatReport->generate();

        // Receivables / payables
        $outstandingReceivables = round(
            $this->query->accountDebits('1310') - $this->query->accountCredits('1310') +
            $this->query->accountDebits('1320') - $this->query->accountCredits('1320'),
            3,
        );
        $outstandingPayables = round(
            $this->query->accountCredits('2200') - $this->query->accountDebits('2200'),
            3,
        );

        // Reconciliation status
        $reconciliationResults = $this->reconciliation->reconcile();
        $hasMismatches = collect($reconciliationResults)->contains(fn ($r) => $r['status'] === 'MISMATCH');

        // Alerts
        $alerts = [];
        foreach ($providerWallets as $pw) {
            if ($pw['balance'] < 500) {
                $alerts[] = [
                    'type' => 'low_balance',
                    'message' => "Low balance on {$pw['name']}: {$pw['currency']} {$pw['balance']}",
                    'severity' => 'warning',
                ];
            }
        }
        if ($hasMismatches) {
            $alerts[] = [
                'type' => 'reconciliation_mismatch',
                'message' => 'Wallet vs ledger reconciliation has mismatches. Please investigate.',
                'severity' => 'danger',
            ];
        }

        return Inertia::render('Accounting/Dashboard', [
            'walletSummary' => [
                'operatingBalance' => $operatingBalance,
                'providerWallets' => $providerWallets,
                'merchantWallet' => $merchantBalance,
            ],
            'period' => ['from' => $from, 'to' => $to],
            'revenue' => [
                'total' => round($totalRevenue, 3),
                'byProduct' => $revenueByProduct,
            ],
            'costOfSales' => round($totalCost, 3),
            'grossMargin' => $grossMargin,
            'grossMarginPct' => $grossMarginPct,
            'vatPayable' => $vat['net_payable'],
            'outstandingReceivables' => max(0.0, $outstandingReceivables),
            'outstandingPayables' => max(0.0, $outstandingPayables),
            'reconciliationStatus' => $hasMismatches ? 'has_mismatches' : 'all_matched',
            'alerts' => $alerts,
        ]);
    }
}
