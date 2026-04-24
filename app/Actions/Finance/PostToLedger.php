<?php

namespace App\Actions\Finance;

use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Services\Finance\LedgerDriver;
use Illuminate\Support\Facades\DB;

class PostToLedger
{
    public function __construct(
        protected LedgerDriver $ledgerDriver,
    ) {}

    public function execute(Order $order, bool $includeOwnCredentials = true): void
    {
        $order->loadMissing('items');

        DB::transaction(function () use ($order, $includeOwnCredentials): void {
            foreach ($order->items as $item) {
                if (! $this->shouldPostItem($item, $includeOwnCredentials)) {
                    continue;
                }

                $journalId = $this->ledgerDriver->postOperationJournal(
                    source: 'order_'.$order->id,
                    description: "Sale of {$item->product_type} for order {$order->number}",
                    entries: $this->buildEntries($item),
                );

                $item->update([
                    'ledger_entry_id' => $journalId,
                ]);
            }
        });
    }

    protected function shouldPostItem(OrderItem $item, bool $includeOwnCredentials): bool
    {
        if ($item->ledger_entry_id !== null) {
            return false;
        }

        if ($item->wallet_transaction_id !== null) {
            return true;
        }

        return $includeOwnCredentials && $item->airline_transaction_id !== null;
    }

    /**
     * @return array<int, array{account:string, direction:string, amount:float}>
     */
    protected function buildEntries(OrderItem $item): array
    {
        $entries = [
            [
                'account' => '1300',
                'direction' => 'debit',
                'amount' => (float) $item->total_amount,
            ],
            [
                'account' => $this->revenueAccountForProduct((string) $item->product_type),
                'direction' => 'credit',
                'amount' => (float) $item->net_fare,
            ],
        ];

        foreach ($this->normalizeTaxes($item->taxes) as $tax) {
            $entries[] = [
                'account' => $this->taxAccountForCode((string) ($tax['code'] ?? 'GEN')),
                'direction' => 'credit',
                'amount' => (float) ($tax['amount'] ?? 0),
            ];
        }

        $commissionAmount = (float) ($item->commission_amount ?? 0);
        if ($commissionAmount > 0) {
            $entries[] = [
                'account' => '6100',
                'direction' => 'debit',
                'amount' => $commissionAmount,
            ];

            $entries[] = [
                'account' => '2300',
                'direction' => 'credit',
                'amount' => $commissionAmount,
            ];
        }

        return array_values(array_filter($entries, function (array $entry): bool {
            return $entry['amount'] > 0;
        }));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeTaxes(mixed $taxes): array
    {
        if (! is_array($taxes)) {
            return [];
        }

        return array_values(array_filter($taxes, function (mixed $tax): bool {
            return is_array($tax) && (float) ($tax['amount'] ?? 0) > 0;
        }));
    }

    protected function revenueAccountForProduct(string $productType): string
    {
        return match ($productType) {
            'flight', 'ticket' => '3100',
            default => '3190',
        };
    }

    protected function taxAccountForCode(string $taxCode): string
    {
        $normalized = strtoupper(trim($taxCode));

        if ($normalized === 'ST') {
            return '2200_ST';
        }

        return '2200';
    }
}
