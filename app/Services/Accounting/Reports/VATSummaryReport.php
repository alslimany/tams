<?php

namespace App\Services\Accounting\Reports;

use App\Services\Accounting\LedgerQueryService;

/**
 * Reports VAT collected (account 2400) for a given period.
 *
 * VAT Payable (2400) is a credit-balance liability account.
 * Credits = VAT collected from customers.
 * Debits  = VAT reversed on cancellations.
 * Net     = VAT currently owed to the tax authority.
 */
class VATSummaryReport
{
    public function __construct(
        private readonly LedgerQueryService $query,
    ) {}

    /**
     * Generate VAT summary.
     *
     * @return array{collected: float, reversed: float, net_payable: float}
     */
    public function generate(): array
    {
        $collected = $this->query->accountCredits('2400');
        $reversed = $this->query->accountDebits('2400');
        $netPayable = round($collected - $reversed, 3);

        return [
            'collected' => round($collected, 3),
            'reversed' => round($reversed, 3),
            'net_payable' => $netPayable,
        ];
    }
}
