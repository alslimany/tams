<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\LedgerQueryService;
use App\Services\Accounting\Reports\MerchantSettlementAgingReport;
use Inertia\Inertia;
use Inertia\Response;

class SettlementLedgerController extends Controller
{
    public function __construct(
        private readonly LedgerQueryService $query,
        private readonly MerchantSettlementAgingReport $agingReport,
    ) {}

    public function index(): Response
    {
        $tenant = tenant();
        $agencyType = $tenant?->type ?? 'direct';

        $outstanding = [];
        $recentBatches = [];
        $totalOutstanding = 0.0;
        $payableTo = [];
        $totalPayable = 0.0;

        if ($agencyType === 'network') {
            // Outstanding receivables from account 1320 (Merchant Receivable)
            $totalDebits = $this->query->accountDebits('1320');
            $totalCredits = $this->query->accountCredits('1320');
            $totalOutstanding = round(max(0.0, $totalDebits - $totalCredits), 3);

            // We surface aggregate data — per-merchant breakdown requires merchant tenant queries
            // which are out of scope for this controller; show aggregate row
            if ($totalOutstanding > 0) {
                $outstanding = [
                    [
                        'merchantId' => 'aggregate',
                        'merchantName' => 'All Merchants',
                        'tenantId' => null,
                        'amount' => $totalOutstanding,
                        'oldestUnpaidDate' => null,
                        'orderCount' => null,
                    ],
                ];
            }
        } elseif ($agencyType === 'merchant') {
            // Payable to network agency — account 2200 (Network Agency Payable)
            $totalPayableCredits = $this->query->accountCredits('2200');
            $totalPayableDebits = $this->query->accountDebits('2200');
            $totalPayable = round(max(0.0, $totalPayableCredits - $totalPayableDebits), 3);

            if ($totalPayable > 0) {
                $payableTo = [
                    [
                        'agencyName' => 'Network Agency',
                        'amount' => $totalPayable,
                        'oldestUnpaidDate' => null,
                    ],
                ];
            }
        }

        return Inertia::render('Accounting/Settlement/Index', [
            'agencyType' => $agencyType,
            'outstanding' => $outstanding,
            'recentBatches' => $recentBatches,
            'totalOutstanding' => $totalOutstanding,
            'payableTo' => $payableTo,
            'totalPayable' => $totalPayable,
        ]);
    }

    public function aging(): Response
    {
        $aging = $this->agingReport->generate();

        // Build aging table rows — aggregate only (per-merchant requires cross-tenant queries)
        $rows = [
            [
                'merchantId' => 'aggregate',
                'merchantName' => 'All Merchants',
                'current' => $aging['outstanding'],
                'days31to60' => 0.0,
                'days61to90' => 0.0,
                'days90plus' => 0.0,
                'total' => $aging['outstanding'],
            ],
        ];

        $totals = [
            'current' => $aging['outstanding'],
            'days31to60' => 0.0,
            'days61to90' => 0.0,
            'days90plus' => 0.0,
            'total' => $aging['outstanding'],
        ];

        return Inertia::render('Accounting/Settlement/MerchantAging', [
            'rows' => $rows,
            'totals' => $totals,
            'summary' => $aging,
        ]);
    }
}
