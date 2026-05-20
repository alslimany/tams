<?php

namespace App\Services\Accounting\Reports;

use App\Services\Accounting\LedgerQueryService;

/**
 * Reports gross margin per product type.
 *
 * Gross Margin = Revenue (4xxx) − Cost of Goods Sold (5xxx)
 *
 * Revenue accounts: 4100 (airline), 4200 (hotel), 4300 (insurance), 4400 (esim), 4500 (other)
 * Cost accounts:    5100 (airline), 5200 (hotel), 5300 (insurance), 5400 (esim), 5500 (other)
 */
class GrossMarginReport
{
    /** @var array<string, array{revenue: string, cost: string}> */
    private const ACCOUNTS = [
        'airline' => ['revenue' => '4100', 'cost' => '5100'],
        'hotel' => ['revenue' => '4200', 'cost' => '5200'],
        'insurance' => ['revenue' => '4300', 'cost' => '5300'],
        'esim' => ['revenue' => '4400', 'cost' => '5400'],
        'other' => ['revenue' => '4500', 'cost' => '5500'],
    ];

    public function __construct(
        private readonly LedgerQueryService $query,
    ) {}

    /**
     * Generate gross margin breakdown by product type.
     *
     * @return array<string, array{revenue: float, cost: float, gross_profit: float, margin_pct: float}>
     */
    public function generate(): array
    {
        $result = [];

        foreach (self::ACCOUNTS as $productType => $accounts) {
            $revenue = round(
                $this->query->accountCredits($accounts['revenue']) - $this->query->accountDebits($accounts['revenue']),
                3
            );
            $cost = round(
                $this->query->accountDebits($accounts['cost']) - $this->query->accountCredits($accounts['cost']),
                3
            );
            $grossProfit = round($revenue - $cost, 3);
            $marginPct = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 2) : 0.0;

            $result[$productType] = [
                'revenue' => $revenue,
                'cost' => $cost,
                'gross_profit' => $grossProfit,
                'margin_pct' => $marginPct,
            ];
        }

        return $result;
    }
}
