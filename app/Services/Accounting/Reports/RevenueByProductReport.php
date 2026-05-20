<?php

namespace App\Services\Accounting\Reports;

use App\Services\Accounting\LedgerQueryService;

/**
 * Reports net revenue per product type from ledger accounts 4100–4400.
 *
 * Revenue is stored as credits in the revenue accounts (positive amounts in abivia).
 * Returns net revenue (credits minus any debits/reversals) per product type.
 */
class RevenueByProductReport
{
    /** @var array<string, string> Maps product type to ledger account code. */
    private const REVENUE_ACCOUNTS = [
        'airline' => '4100',
        'hotel' => '4200',
        'insurance' => '4300',
        'esim' => '4400',
        'other' => '4500',
    ];

    public function __construct(
        private readonly LedgerQueryService $query,
    ) {}

    /**
     * Generate revenue breakdown by product type.
     *
     * @return array<string, float> Product type → net revenue amount.
     */
    public function generate(): array
    {
        $result = [];

        foreach (self::REVENUE_ACCOUNTS as $productType => $accountCode) {
            $credits = $this->query->accountCredits($accountCode);
            $debits = $this->query->accountDebits($accountCode);
            $result[$productType] = round($credits - $debits, 3);
        }

        return $result;
    }
}
