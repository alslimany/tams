<?php

namespace App\Services\Accounting\Reports;

use App\Services\Accounting\LedgerQueryService;

/**
 * Reports outstanding merchant settlement balances.
 *
 * Agency side: account 1320 (Merchant Receivable) — debits = amounts owed by merchants.
 * Merchant side: account 2200 (Network Agency Payable) — credits = amounts owed to agency.
 *
 * Outstanding = total debits on 1320 minus total credits on 1320 (net receivable).
 * Settled     = total credits on 1320 (settlements received).
 */
class MerchantSettlementAgingReport
{
    public function __construct(
        private readonly LedgerQueryService $query,
    ) {}

    /**
     * Generate settlement aging report.
     *
     * @return array{total_receivable: float, total_settled: float, outstanding: float}
     */
    public function generate(): array
    {
        $totalDebits = $this->query->accountDebits('1320');   // amounts billed to merchants
        $totalCredits = $this->query->accountCredits('1320'); // settlements received
        $outstanding = round($totalDebits - $totalCredits, 3);

        return [
            'total_receivable' => round($totalDebits, 3),
            'total_settled' => round($totalCredits, 3),
            'outstanding' => max(0.0, $outstanding),
        ];
    }
}
