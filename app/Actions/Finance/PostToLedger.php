<?php

namespace App\Actions\Finance;

use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Support\Facades\DB;

class PostToLedger
{
    public function __construct(
        protected LedgerPostingService $ledgerPostingService,
    ) {}

    public function execute(Order $order, bool $includeOwnCredentials = true): void
    {
        $order->loadMissing('items');

        DB::transaction(function () use ($order): void {
            foreach ($order->items as $item) {
                if (! $this->shouldPostItem($item)) {
                    continue;
                }

                $journalEntry = $this->ledgerPostingService->post(
                    journal: $this->resolveJournal((string) $item->product_type),
                    description: "Sale of {$item->product_type} for order {$order->number}",
                    reference: "order:{$order->id}|item:{$item->id}",
                    clearing: true,
                    details: $this->buildDetails($item),
                );

                $item->update([
                    'ledger_entry_id' => $journalEntry->journalEntryId,
                ]);
            }
        });
    }

    protected function shouldPostItem(OrderItem $item): bool
    {
        if ($item->ledger_entry_id !== null) {
            return false;
        }

        return $item->wallet_transaction_id !== null;
    }

    /**
     * Build the abivia-format detail lines for a sale journal entry.
     *
     * Correct balanced entry for an airline ticket sale (6 lines when taxes > 0):
     *
     *   Entry 1 — Customer sale (revenue side):
     *     Dr 1310  Customer Receivable      → full selling price (base fare + taxes)
     *     Cr 4xxx  Revenue Account          → base fare net of commission
     *     Cr 4500  Service Fees & Markup    → commission amount (omitted if zero)
     *     Cr 2410  Airline Tax Payable      → tax total (omitted if zero)
     *
     *   Entry 2 — Provider cost (wallet side):
     *     Dr 5xxx  Provider Cost (COGS)     → true provider cost (base fare net of commission + taxes)
     *     Cr 1xxx  Provider Wallet          → same amount (prepaid asset consumed)
     *
     * net_fare = base fare only (set by ApplyFinancialSourceAndCommission from fare_store).
     * total_tax = tax total (set by ApplyFinancialSourceAndCommission from fare_store).
     * commission is on base fare only — taxes are always pass-through.
     *
     * @return array<int, array{code: string, debit?: string, credit?: string}>
     */
    protected function buildDetails(OrderItem $item): array
    {
        $productType = $this->normalizeProductType((string) $item->product_type);
        $baseFare = round((float) $item->net_fare, 3);
        $taxTotal = round((float) $item->total_tax, 3);
        $sellingPrice = round((float) $item->total_amount, 3);
        $commissionAmount = round($this->resolveCommissionAmount($item), 3);

        // True provider cost = base fare net of commission + taxes (pass-through)
        $providerCost = round($baseFare - $commissionAmount + $taxTotal, 3);

        // Revenue posted = base fare net of commission (taxes go to 2410, not revenue)
        $revenueAmount = round($baseFare - $commissionAmount, 3);

        $revenueAccount = $this->ledgerPostingService->revenueAccount($productType);
        $costAccount = $this->ledgerPostingService->costAccount($productType);
        $providerWalletAccount = $this->ledgerPostingService->providerWalletAccount($productType);

        $details = [];

        // Dr 1310 — Customer Receivable (full selling price = base fare + taxes)
        if ($sellingPrice > 0) {
            $details[] = ['code' => $this->ledgerPostingService->receivableAccount($productType), 'debit' => (string) $sellingPrice];
        }

        // Cr 4xxx — Revenue (base fare net of commission; taxes excluded)
        if ($revenueAmount > 0) {
            $details[] = ['code' => $revenueAccount, 'credit' => (string) $revenueAmount];
        }

        // Cr 4500 — Service Fees & Markup (commission on base fare)
        if ($commissionAmount > 0) {
            $details[] = ['code' => $this->ledgerPostingService->marginAccount($productType), 'credit' => (string) $commissionAmount];
        }

        // Cr 2410 — Airline Tax Payable (pass-through taxes owed to authority)
        if ($taxTotal > 0) {
            $details[] = ['code' => $this->ledgerPostingService->taxPayableAccount($productType), 'credit' => (string) $taxTotal];
        }

        // Dr 5xxx — Provider Cost / COGS (net fare after commission + taxes)
        if ($providerCost > 0) {
            $details[] = ['code' => $costAccount, 'debit' => (string) $providerCost];
        }

        // Cr 1xxx — Provider Wallet (prepaid asset consumed)
        if ($providerCost > 0) {
            $details[] = ['code' => $providerWalletAccount, 'credit' => (string) $providerCost];
        }

        return $details;
    }

    protected function resolveCommissionAmount(OrderItem $item): float
    {
        $financialSource = (string) data_get($item->item_details, 'financial_source', '');

        if ($financialSource === 'master_agency_supply') {
            return (float) ($item->agent_commission ?? 0);
        }

        return (float) ($item->commission_amount ?? 0);
    }

    /**
     * Normalise legacy product_type values to the canonical set used by LedgerPostingService.
     */
    protected function normalizeProductType(string $productType): string
    {
        return match ($productType) {
            'flight', 'ticket' => 'airline',
            default => $productType,
        };
    }

    protected function resolveJournal(string $productType): string
    {
        return $this->ledgerPostingService->resolveJournal(
            $this->normalizeProductType($productType),
        );
    }
}
