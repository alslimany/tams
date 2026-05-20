<?php

namespace App\Services\Accounting;

use Abivia\Ledger\Http\Controllers\JournalEntryController;
use Abivia\Ledger\Messages\Entry;
use Abivia\Ledger\Models\JournalEntry;
use Abivia\Ledger\Models\SubJournal;
use App\DTOs\Accounting\IssuanceDTO;
use Bavix\Wallet\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Date;

class LedgerPostingService
{
    /**
     * Post a simple double-entry journal from a wallet transaction's meta.
     *
     * The transaction meta must contain:
     *   - ledger_accounts.debit  (account code)
     *   - ledger_accounts.credit (account code)
     *   - order_type             (airline|hotel|insurance|esim|general)
     *   - tx_type                (issuance|deposit|refund|cancellation|settlement)
     *   - order_id               (optional)
     *   - reference              (optional)
     */
    public function postFromWalletTransaction(Transaction $walletTx): JournalEntry
    {
        $meta = $walletTx->meta ?? [];
        $amount = abs((float) $walletTx->amountFloat);
        $journal = $this->resolveJournal($meta['order_type'] ?? 'general');
        $orderId = $meta['order_id'] ?? null;
        $reference = $orderId
            ? "order:{$orderId}|wallet_tx:{$walletTx->uuid}"
            : "wallet_tx:{$walletTx->uuid}";

        return $this->post(
            journal: $journal,
            description: $meta['tx_type'] ?? 'wallet_transaction',
            reference: $reference,
            details: [
                ['code' => $meta['ledger_accounts']['debit'], 'debit' => (string) $amount],
                ['code' => $meta['ledger_accounts']['credit'], 'credit' => (string) $amount],
            ],
        );
    }

    /**
     * Post a multi-line issuance journal entry covering revenue, VAT, and provider cost.
     *
     * Debits:
     *   1110  Operating Wallet (customer payment received)
     *   5xxx  Provider Cost (COGS)
     *
     * Credits:
     *   4xxx  Revenue (net of VAT)
     *   2400  VAT Payable
     *   1xxx  Provider Wallet (prepaid asset consumed)
     */
    public function postIssuanceEntry(IssuanceDTO $dto): JournalEntry
    {
        $revenueNet = $dto->sellingPrice - $dto->vatAmount;
        $journal = $this->resolveJournal($dto->productType);

        return $this->post(
            journal: $journal,
            description: "issuance:{$dto->productType}:order:{$dto->orderId}",
            reference: "order:{$dto->orderId}|ref:{$dto->providerReference}",
            clearing: true,
            details: [
                // Customer payment received — debit operating wallet
                ['code' => '1110', 'debit' => (string) $dto->sellingPrice],

                // Revenue (net of VAT) — credit revenue account
                ['code' => $this->revenueAccount($dto->productType), 'credit' => (string) $revenueNet],

                // VAT payable — credit
                ['code' => '2400', 'credit' => (string) $dto->vatAmount],

                // Provider cost (COGS) — debit
                ['code' => $this->costAccount($dto->productType), 'debit' => (string) $dto->providerCost],

                // Provider wallet consumed — credit prepaid asset
                ['code' => $this->providerWalletAccount($dto->productType), 'credit' => (string) $dto->providerCost],
            ],
        );
    }

    /**
     * Post the merchant-side issuance journal entry.
     *
     * Merchant books record:
     *   Dr 1120  Merchant Wallet (wholesale price paid out)
     *   Dr 5500  Merchant Wholesale Cost (COGS at wholesale)
     *   Cr 4xxx  Revenue (net of VAT)
     *   Cr 2400  VAT Payable
     *   Cr 2200  Network Agency Payable (wholesale price owed to agency)
     */
    public function postMerchantIssuanceEntry(IssuanceDTO $dto): JournalEntry
    {
        $revenueNet = $dto->sellingPrice - $dto->vatAmount;
        $wholesalePrice = $dto->wholesalePrice ?? $dto->providerCost;
        $journal = $this->resolveJournal($dto->productType);

        // Balanced entry:
        //   Debits:  1110 (customer payment in) + 5500 (wholesale COGS)
        //   Credits: 4xxx (revenue net) + 2400 (VAT) + 2200 (agency payable)
        //   Check:   sellingPrice + wholesalePrice = revenueNet + vatAmount + wholesalePrice ✓
        return $this->post(
            journal: $journal,
            description: "merchant-issuance:{$dto->productType}:order:{$dto->orderId}",
            reference: "order:{$dto->orderId}|ref:{$dto->providerReference}",
            clearing: true,
            details: [
                // Customer payment received into operating wallet
                ['code' => '1110', 'debit' => (string) $dto->sellingPrice],

                // Wholesale cost (COGS — what merchant owes the agency)
                ['code' => '5500', 'debit' => (string) $wholesalePrice],

                // Revenue net of VAT
                ['code' => $this->revenueAccount($dto->productType), 'credit' => (string) $revenueNet],

                // VAT payable
                ['code' => '2400', 'credit' => (string) $dto->vatAmount],

                // Network agency payable (wholesale price owed to agency)
                ['code' => '2200', 'credit' => (string) $wholesalePrice],
            ],
        );
    }

    /**
     * Post the agency-side network entry when a merchant issues through the agency.
     *
     * Agency books record:
     *   Dr 1320  Merchant Receivable (wholesale price due from merchant)
     *   Dr 5xxx  Provider Cost (COGS at provider cost)
     *   Cr 4600  Network Commission Income (wholesale - provider cost)
     *   Cr 7200  Settlement Clearing (wholesale price — to be settled)
     *   Cr 1xxx  Provider Wallet (prepaid asset consumed)
     *
     * @param  array{order_id: string, product_type: string, wholesale_price: float, provider_cost: float, commission: float, merchant_tenant: string}  $data
     */
    public function postAgencyNetworkEntry(array $data): JournalEntry
    {
        $productType = $data['product_type'];
        $wholesalePrice = (float) $data['wholesale_price'];
        $providerCost = (float) $data['provider_cost'];
        $commission = (float) $data['commission'];
        $orderId = $data['order_id'];

        // Balanced entry:
        //   Debits:  1320 (merchant receivable = wholesale price)
        //   Credits: 4600 (commission = wholesale - provider cost) + 1xxx (provider wallet = provider cost)
        //   Check:   wholesalePrice = commission + providerCost ✓
        return $this->post(
            journal: $this->resolveJournal($productType),
            description: "agency-network:{$productType}:order:{$orderId}",
            reference: "order:{$orderId}|merchant:{$data['merchant_tenant']}",
            clearing: false,
            details: [
                // Merchant receivable (wholesale price due from merchant)
                ['code' => '1320', 'debit' => (string) $wholesalePrice],

                // Network commission income (our margin)
                ['code' => '4600', 'credit' => (string) $commission],

                // Provider wallet consumed (prepaid asset)
                ['code' => $this->providerWalletAccount($productType), 'credit' => (string) $providerCost],
            ],
        );
    }

    /**
     * Post a reversal entry that mirrors and negates a prior issuance.
     *
     * Swaps all debits and credits from the original issuance entry:
     *
     *   Cr 1310  Customer Receivable      → refund full selling price (net of cancellation fee)
     *   Dr 4xxx  Revenue Account          → reverse base fare revenue
     *   Dr 4500  Service Fees & Markup    → reverse commission (if any)
     *   Dr 2410  Airline Tax Payable      → reverse tax liability (if any)
     *   Cr 5xxx  Provider Cost (COGS)     → reverse provider cost
     *   Dr 1xxx  Provider Wallet          → restore prepaid asset
     *
     * If a cancellation fee applies, an additional line posts the fee to 4700.
     */

    /**
     * Find the most recent abivia journal entry whose extra JSON contains the given reference string.
     * Returns null if no matching entry exists.
     */
    public function findOrphanedEntry(string $reference): ?JournalEntry
    {
        return JournalEntry::with('details.account')
            ->where('extra', 'like', '%'.addslashes($reference).'%')
            ->latest('journalEntryId')
            ->first();
    }

    /**
     * Post an exact mirror reversal of an existing journal entry.
     *
     * Each debit line becomes a credit and each credit line becomes a debit,
     * using the exact amounts from the original entry. This guarantees the
     * reversal is always balanced regardless of how the original was structured.
     *
     * @param  JournalEntry  $original  The entry to reverse (must have details.account loaded).
     * @param  string  $originalOrderId  Used to build the reversal reference.
     */
    public function postMirrorReversal(JournalEntry $original, string $originalOrderId): JournalEntry
    {
        $details = [];

        foreach ($original->details as $detail) {
            $accountCode = $detail->account?->code;
            if ($accountCode === null) {
                continue;
            }

            // abivia stores debits as negative BCD, credits as positive BCD.
            $amount = (string) abs((float) $detail->amount);
            $isDebit = bccomp($detail->amount, '0', 6) < 0;

            // Flip: original debit → reversal credit, original credit → reversal debit.
            $details[] = $isDebit
                ? ['code' => $accountCode, 'credit' => $amount]
                : ['code' => $accountCode, 'debit' => $amount];
        }

        return $this->post(
            journal: $original->subJournalUuid
                ? (SubJournal::find($original->subJournalUuid)?->code ?? 'GEN')
                : 'GEN',
            description: "reversal:order:{$originalOrderId}",
            reference: "reversal:order:{$originalOrderId}",
            clearing: true,
            details: $details,
        );
    }

    public function postReversalEntry(
        string $originalOrderId,
        float $sellingPrice,
        string $productType,
        ?float $taxTotal = null,
        ?float $commissionAmount = null,
        ?float $providerCost = null,
        ?float $cancellationFee = null,
    ): JournalEntry {
        $taxTotal ??= 0.0;
        $commissionAmount ??= 0.0;
        $providerCost ??= 0.0;

        // Base fare revenue = selling price − taxes (mirrors the issue entry)
        $baseFareRevenue = round($sellingPrice - $taxTotal, 3);
        // Revenue net of commission (mirrors Cr 4xxx in issue entry)
        $revenueNet = round($baseFareRevenue - $commissionAmount, 3);

        $journal = $this->resolveJournal($productType);
        $refundAmount = round($sellingPrice - ($cancellationFee ?? 0.0), 3);

        $details = [
            // Reverse customer receivable — credit back to customer (net of cancellation fee)
            ['code' => '1310', 'credit' => (string) $refundAmount],

            // Reverse revenue — debit revenue account
            ['code' => $this->revenueAccount($productType), 'debit' => (string) $revenueNet],
        ];

        // Reverse commission — debit service fees account
        if ($commissionAmount > 0) {
            $details[] = ['code' => '4500', 'debit' => (string) $commissionAmount];
        }

        // Reverse tax liability — debit tax payable
        if ($taxTotal > 0) {
            $details[] = ['code' => '2410', 'debit' => (string) $taxTotal];
        }

        // Reverse provider cost — credit COGS
        if ($providerCost > 0) {
            $details[] = ['code' => $this->costAccount($productType), 'credit' => (string) $providerCost];
        }

        // Restore provider wallet — debit prepaid asset
        if ($providerCost > 0) {
            $details[] = ['code' => $this->providerWalletAccount($productType), 'debit' => (string) $providerCost];
        }

        if ($cancellationFee !== null && $cancellationFee > 0) {
            // Cancellation fee retained — credit fee income account
            $details[] = ['code' => '4700', 'credit' => (string) $cancellationFee];
        }

        return $this->post(
            journal: $journal,
            description: "reversal:{$productType}:order:{$originalOrderId}",
            reference: "reversal:order:{$originalOrderId}",
            clearing: true,
            details: $details,
        );
    }

    /**
     * Core posting method — wraps abivia Entry::fromArray + JournalEntryController::add().
     *
     * Forces Date::use(Carbon::class) for the duration of the call so that
     * Eloquent timestamps on abivia models are Carbon instances (not CarbonImmutable),
     * satisfying the Revision::create() signature.
     *
     * @param  array<int, array{code: string, debit?: string, credit?: string}>  $details
     */
    public function post(
        string $journal,
        string $description,
        string $reference,
        array $details,
        bool $clearing = false,
    ): JournalEntry {
        $originalDateClass = get_class(Date::now());
        Date::use(Carbon::class);

        try {
            $message = Entry::fromArray([
                'journal' => $journal,
                'description' => $description,
                'clearing' => $clearing,
                'extra' => json_encode(['reference' => $reference], JSON_THROW_ON_ERROR),
                'transDate' => Carbon::now()->toDateTimeString(),
                'details' => $details,
            ]);

            return (new JournalEntryController)->add($message);
        } finally {
            Date::use($originalDateClass);
        }
    }

    /**
     * Post the merchant-side settlement entry.
     *
     * Merchant clears what it owes the network agency:
     *   Dr 2200  Network Agency Payable (liability cleared)
     *   Cr 1120  Merchant Wallet (cash paid out)
     *
     * @param  string  $batchReference  Settlement batch reference.
     * @param  string  $agencyTenantId  The agency tenant receiving the settlement.
     */
    public function postMerchantSettlementEntry(
        float $amount,
        string $batchReference,
        string $agencyTenantId,
    ): JournalEntry {
        return $this->post(
            journal: 'STL',
            description: "settlement:merchant-to-agency:batch:{$batchReference}",
            reference: "batch:{$batchReference}|agency:{$agencyTenantId}",
            clearing: false,
            details: [
                // Clear the payable to the network agency
                ['code' => '2200', 'debit' => (string) $amount],
                // Merchant wallet debited (cash out)
                ['code' => '1120', 'credit' => (string) $amount],
            ],
        );
    }

    /**
     * Post the agency-side settlement entry.
     *
     * Agency clears the receivable from the merchant:
     *   Dr 1110  Operating Wallet (cash received)
     *   Cr 1320  Merchant Receivable (asset cleared)
     *
     * @param  string  $batchReference  Settlement batch reference.
     * @param  string  $merchantTenantId  The merchant tenant paying the settlement.
     */
    public function postAgencySettlementEntry(
        float $amount,
        string $batchReference,
        string $merchantTenantId,
    ): JournalEntry {
        return $this->post(
            journal: 'STL',
            description: "settlement:agency-from-merchant:batch:{$batchReference}",
            reference: "batch:{$batchReference}|merchant:{$merchantTenantId}",
            clearing: false,
            details: [
                // Operating wallet credited (cash in)
                ['code' => '1110', 'debit' => (string) $amount],
                // Clear the merchant receivable
                ['code' => '1320', 'credit' => (string) $amount],
            ],
        );
    }

    public function resolveJournal(string $productType): string
    {
        return match ($productType) {
            'airline' => 'AIR',
            'hotel' => 'HTL',
            'insurance' => 'INS',
            'esim' => 'ESM',
            'settlement' => 'STL',
            default => 'GEN',
        };
    }

    public function revenueAccount(string $productType): string
    {
        return match ($productType) {
            'airline' => '4100',
            'hotel' => '4200',
            'insurance' => '4300',
            'esim' => '4400',
            default => '4500',
        };
    }

    public function costAccount(string $productType): string
    {
        return match ($productType) {
            'airline' => '5100',
            'hotel' => '5200',
            'insurance' => '5300',
            'esim' => '5400',
            default => '5500',
        };
    }

    public function providerWalletAccount(string $productType): string
    {
        return match ($productType) {
            'airline' => '1210',
            'hotel' => '1220',
            'insurance' => '1230',
            'esim' => '1240',
            default => '1200',
        };
    }
}
