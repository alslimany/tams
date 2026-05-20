# Booknow Accounting System — Full Implementation Plan

**Stack:** Laravel multi-tenant · `bavix/laravel-wallet` v11.x · `abivia/ledger` v1.x  
**Architecture:** Central DB + Tenant DB per agency/merchant  
**Revenue model:** Gross (full selling price)  
**Accounting:** Full double-entry  
**Scope:** Direct Agency · Network Agency · Merchant Agency  
**Default Agency:** Deprecated — removed from scope  

---

## How to Use This Document

Each phase below is **self-contained**. Hand it to the AI agent one phase at a time. Each phase ends with a **Test Suite** the agent must pass before moving to the next phase. Do not proceed to the next phase unless all tests in the current phase are green.

---

## Phase 1 — Package Installation & Tenant Bootstrap

### 1.1 Install Packages

```bash
composer require bavix/laravel-wallet
composer require abivia/ledger
```

Publish and migrate both packages **inside the tenant migration pipeline**, not the central database migration:

```bash
php artisan vendor:publish --provider="Bavix\Wallet\WalletServiceProvider"
php artisan vendor:publish --provider="Abivia\Ledger\LedgerServiceProvider"
```

### 1.2 Tenant Migration Pipeline

Both package migrations must run in the **tenant database**, not the central database.

In your tenant migration service (or `TenantMigrated` event listener), add both packages to the tenant migration path:

```php
// In your tenant bootstrapper / stancl/tenancy AfterCreatingTenant listener
Artisan::call('migrate', [
    '--database' => 'tenant',
    '--path'     => [
        'database/migrations/tenant',
        'vendor/bavix/laravel-wallet/database/migrations',
        // abivia/ledger publishes its migrations to database/migrations — already covered
    ],
    '--force'    => true,
]);
```

> ⚠️ Never run wallet or ledger migrations on the central database.

### 1.3 Tenant Database Connection

Ensure your tenant DB connection is named `tenant` and is switched before any wallet or ledger operation. All Eloquent models that use `HasWallets` must resolve against the active tenant connection.

### 1.4 Environment Variables

Add to each tenant `.env` or tenant config resolver:

```env
LEDGER_LOG_CHANNEL=daily
```

---

## Phase 1 — Test Suite

```php
// tests/Tenant/Phase1/PackageBootstrapTest.php

class PackageBootstrapTest extends TenantTestCase
{
    /** @test */
    public function wallet_tables_exist_in_tenant_database(): void
    {
        $this->assertTrue(Schema::connection('tenant')->hasTable('wallets'));
        $this->assertTrue(Schema::connection('tenant')->hasTable('transactions'));
        $this->assertTrue(Schema::connection('tenant')->hasTable('transfers'));
    }

    /** @test */
    public function ledger_tables_exist_in_tenant_database(): void
    {
        $this->assertTrue(Schema::connection('tenant')->hasTable('ledger_accounts'));
        $this->assertTrue(Schema::connection('tenant')->hasTable('journal_entries'));
        $this->assertTrue(Schema::connection('tenant')->hasTable('journal_entry_details'));
    }

    /** @test */
    public function wallet_tables_do_not_exist_in_central_database(): void
    {
        $this->assertFalse(Schema::connection('central')->hasTable('wallets'));
    }

    /** @test */
    public function ledger_tables_do_not_exist_in_central_database(): void
    {
        $this->assertFalse(Schema::connection('central')->hasTable('ledger_accounts'));
    }
}
```

---

## Phase 2 — Chart of Accounts Setup

### 2.1 CoA Template Files

Create three JSON template files at `resources/ledger/templates/`:

- `direct-agency.json`
- `network-agency.json`
- `merchant-agency.json`

All three share the **same account structure** below. The difference is only which sub-journals are active. Use abivia/ledger's `chartPath` config to point to this directory.

### 2.2 Standard Chart of Accounts (All Agency Types)

```json
{
  "ledger": { "name": "Booknow Agency Ledger", "template": true },
  "currency": { "code": "LYD", "decimals": 3 },
  "accounts": [

    { "code": "1000", "name": "Assets",                         "type": "asset" },
    { "code": "1100", "name": "Cash & Bank",                    "parent": "1000" },
    { "code": "1110", "name": "Agency Operating Wallet",        "parent": "1100" },
    { "code": "1120", "name": "Merchant Wallet",                "parent": "1100" },
    { "code": "1200", "name": "Provider Prepaid Balances",      "parent": "1000" },
    { "code": "1210", "name": "Airline Provider Wallet",        "parent": "1200" },
    { "code": "1220", "name": "Hotel Provider Wallet",          "parent": "1200" },
    { "code": "1230", "name": "Insurance Provider Wallet",      "parent": "1200" },
    { "code": "1240", "name": "eSIM Provider Wallet",           "parent": "1200" },
    { "code": "1300", "name": "Receivables",                    "parent": "1000" },
    { "code": "1310", "name": "Customer Receivable",            "parent": "1300" },
    { "code": "1320", "name": "Merchant Receivable",            "parent": "1300" },

    { "code": "2000", "name": "Liabilities",                    "type": "liability" },
    { "code": "2100", "name": "Provider Payables",              "parent": "2000" },
    { "code": "2110", "name": "Airline Provider Payable",       "parent": "2100" },
    { "code": "2120", "name": "Hotel Provider Payable",         "parent": "2100" },
    { "code": "2130", "name": "Insurance Provider Payable",     "parent": "2100" },
    { "code": "2140", "name": "eSIM Provider Payable",          "parent": "2100" },
    { "code": "2200", "name": "Network Agency Payable",         "parent": "2000" },
    { "code": "2300", "name": "Customer Deposits",              "parent": "2000" },
    { "code": "2400", "name": "VAT Payable",                    "parent": "2000" },

    { "code": "3000", "name": "Equity",                         "type": "equity" },
    { "code": "3100", "name": "Agency Capital",                 "parent": "3000" },
    { "code": "3200", "name": "Retained Earnings",              "parent": "3000" },
    { "code": "3300", "name": "Current Year Profit/Loss",       "parent": "3000" },

    { "code": "4000", "name": "Revenue",                        "type": "revenue" },
    { "code": "4100", "name": "Airline Ticket Sales",           "parent": "4000" },
    { "code": "4200", "name": "Hotel Booking Sales",            "parent": "4000" },
    { "code": "4300", "name": "Insurance Premium Sales",        "parent": "4000" },
    { "code": "4400", "name": "eSIM Sales",                     "parent": "4000" },
    { "code": "4500", "name": "Service Fees & Markup",          "parent": "4000" },
    { "code": "4600", "name": "Network Commission Income",      "parent": "4000" },
    { "code": "4700", "name": "Cancellation Fee Income",        "parent": "4000" },

    { "code": "5000", "name": "Cost of Sales",                  "type": "expense" },
    { "code": "5100", "name": "Airline Provider Cost",          "parent": "5000" },
    { "code": "5200", "name": "Hotel Provider Cost",            "parent": "5000" },
    { "code": "5300", "name": "Insurance Provider Cost",        "parent": "5000" },
    { "code": "5400", "name": "eSIM Provider Cost",             "parent": "5000" },
    { "code": "5500", "name": "Merchant Wholesale Cost",        "parent": "5000" },

    { "code": "6000", "name": "Operating Expenses",             "type": "expense" },
    { "code": "6100", "name": "Refunds & Voids",                "parent": "6000" },
    { "code": "6200", "name": "Settlement Adjustments",         "parent": "6000" },
    { "code": "6300", "name": "Exchange Gain/Loss",             "parent": "6000" },

    { "code": "7000", "name": "Settlement Clearing",            "type": "asset" },
    { "code": "7100", "name": "Network Agency Settlement",      "parent": "7000" },
    { "code": "7200", "name": "Merchant Settlement Clearing",   "parent": "7000" },
    { "code": "7400", "name": "Provider Reconciliation",        "parent": "7000" }
  ],

  "journals": [
    { "name": "General",   "subJournalCode": "GEN" },
    { "name": "Airline",   "subJournalCode": "AIR" },
    { "name": "Hotel",     "subJournalCode": "HTL" },
    { "name": "Insurance", "subJournalCode": "INS" },
    { "name": "eSIM",      "subJournalCode": "ESM" },
    { "name": "Settlement","subJournalCode": "STL" }
  ]
}
```

### 2.3 LedgerBootstrapService

Create `App\Services\Accounting\LedgerBootstrapService`:

```php
class LedgerBootstrapService
{
    public function bootstrapForTenant(Agency $agency): void
    {
        $template = $this->resolveTemplate($agency->type); // direct|network|merchant
        $message  = LedgerCreate::fromArray(json_decode(
            file_get_contents(resource_path("ledger/templates/{$template}.json")), true
        ));
        app(LedgerController::class)->create($message);
    }

    private function resolveTemplate(string $type): string
    {
        return match($type) {
            'network'  => 'network-agency',
            'merchant' => 'merchant-agency',
            default    => 'direct-agency',
        };
    }
}
```

Call `LedgerBootstrapService::bootstrapForTenant()` inside the `TenantCreated` event listener, after migrations complete.

---

## Phase 2 — Test Suite

```php
// tests/Tenant/Phase2/ChartOfAccountsTest.php

class ChartOfAccountsTest extends TenantTestCase
{
    /** @test */
    public function direct_agency_ledger_is_bootstrapped_with_correct_accounts(): void
    {
        $agency = Agency::factory()->create(['type' => 'direct']);
        app(LedgerBootstrapService::class)->bootstrapForTenant($agency);

        $requiredCodes = ['1110','1210','1220','1230','1240','2400','4100','4200','5100','7000'];
        foreach ($requiredCodes as $code) {
            $this->assertDatabaseHas('ledger_accounts', ['code' => $code], 'tenant');
        }
    }

    /** @test */
    public function merchant_agency_ledger_is_bootstrapped(): void
    {
        $agency = Agency::factory()->create(['type' => 'merchant']);
        app(LedgerBootstrapService::class)->bootstrapForTenant($agency);

        $this->assertDatabaseHas('ledger_accounts', ['code' => '2200'], 'tenant'); // Network agency payable
        $this->assertDatabaseHas('ledger_accounts', ['code' => '5500'], 'tenant'); // Merchant wholesale cost
    }

    /** @test */
    public function sub_journals_are_created(): void
    {
        $agency = Agency::factory()->create(['type' => 'network']);
        app(LedgerBootstrapService::class)->bootstrapForTenant($agency);

        foreach (['AIR', 'HTL', 'INS', 'ESM', 'STL'] as $code) {
            $this->assertDatabaseHas('ledger_journals', ['subJournalCode' => $code], 'tenant');
        }
    }

    /** @test */
    public function coa_is_isolated_per_tenant(): void
    {
        $agencyA = $this->createTenant('agency-a');
        $agencyB = $this->createTenant('agency-b');

        // Bootstrap both
        $this->runInTenant($agencyA, fn() => app(LedgerBootstrapService::class)->bootstrapForTenant($agencyA));
        $this->runInTenant($agencyB, fn() => app(LedgerBootstrapService::class)->bootstrapForTenant($agencyB));

        // Verify isolation — both have accounts but in different databases
        $countA = $this->runInTenant($agencyA, fn() => DB::table('ledger_accounts')->count());
        $countB = $this->runInTenant($agencyB, fn() => DB::table('ledger_accounts')->count());

        $this->assertGreaterThan(0, $countA);
        $this->assertGreaterThan(0, $countB);
        // They are in separate DBs — no cross contamination possible by design
        $this->assertEquals($countA, $countB); // Same template = same account count
    }
}
```

---

## Phase 3 — Wallet Setup

### 3.1 Wallet-Capable Models

Apply `HasWallets` and `HasWalletFloat` to the following models. All models must use the tenant database connection.

```php
// Agency model
use Bavix\Wallet\Traits\HasWallets;
use Bavix\Wallet\Traits\HasWalletFloat;
use Bavix\Wallet\Interfaces\Wallet;

class Agency extends Model implements Wallet
{
    use HasWallets, HasWalletFloat;
    protected $connection = 'tenant';
}

// ProviderConfig model (owns provider wallets)
class ProviderConfig extends Model implements Wallet
{
    use HasWallets, HasWalletFloat;
    protected $connection = 'tenant';
}

// Merchant model
class Merchant extends Model implements Wallet
{
    use HasWallets, HasWalletFloat;
    protected $connection = 'tenant';
}
```

### 3.2 WalletProvisioningService

Create `App\Services\Wallet\WalletProvisioningService`. This service creates the named wallets when a tenant is bootstrapped or when a provider is configured.

```php
class WalletProvisioningService
{
    // Called on agency tenant creation
    public function provisionAgencyWallets(Agency $agency): void
    {
        $agency->createWallet([
            'name' => 'Operating Wallet',
            'slug' => 'operating',
            'meta' => ['ledger_account' => '1110', 'type' => 'operating'],
        ]);
    }

    // Called when a provider is configured for an agency
    public function provisionProviderWallet(ProviderConfig $provider): void
    {
        $slugMap = [
            'airline'   => ['slug' => 'airline-provider',   'ledger' => '1210'],
            'hotel'     => ['slug' => 'hotel-provider',     'ledger' => '1220'],
            'insurance' => ['slug' => 'insurance-provider', 'ledger' => '1230'],
            'esim'      => ['slug' => 'esim-provider',      'ledger' => '1240'],
        ];

        $cfg = $slugMap[$provider->type];
        $provider->createWallet([
            'name' => ucfirst($provider->type) . ' Provider Wallet',
            'slug' => $cfg['slug'],
            'meta' => ['ledger_account' => $cfg['ledger'], 'type' => 'provider'],
        ]);
    }

    // Called on merchant account creation
    public function provisionMerchantWallet(Merchant $merchant): void
    {
        $merchant->createWallet([
            'name' => 'Merchant Wallet',
            'slug' => 'merchant',
            'meta' => ['ledger_account' => '1120', 'type' => 'merchant'],
        ]);
    }
}
```

### 3.3 Wallet Meta Convention

Every wallet transaction must carry metadata so the ledger bridge (Phase 5) knows what to post. Use this meta schema consistently:

```php
$meta = [
    'order_id'        => $order->id,         // always
    'order_type'      => 'airline',           // airline|hotel|insurance|esim
    'tx_type'         => 'issuance',          // issuance|deposit|refund|cancellation|settlement
    'ledger_accounts' => [                    // which accounts this deduction touches
        'debit'  => '5100',
        'credit' => '1210',
    ],
    'reference'       => $pnr,               // provider reference / PNR / booking ID
    'tenant_id'       => tenant('id'),        // cross-tenant reference for network flows
];
```

---

## Phase 3 — Test Suite

```php
// tests/Tenant/Phase3/WalletProvisioningTest.php

class WalletProvisioningTest extends TenantTestCase
{
    /** @test */
    public function agency_operating_wallet_is_created_on_provisioning(): void
    {
        $agency = Agency::factory()->create();
        app(WalletProvisioningService::class)->provisionAgencyWallets($agency);

        $wallet = $agency->getWallet('operating');
        $this->assertNotNull($wallet);
        $this->assertEquals('1110', $wallet->meta['ledger_account']);
    }

    /** @test */
    public function airline_provider_wallet_is_created_with_correct_slug_and_meta(): void
    {
        $provider = ProviderConfig::factory()->create(['type' => 'airline']);
        app(WalletProvisioningService::class)->provisionProviderWallet($provider);

        $wallet = $provider->getWallet('airline-provider');
        $this->assertNotNull($wallet);
        $this->assertEquals('1210', $wallet->meta['ledger_account']);
    }

    /** @test */
    public function all_four_provider_wallet_types_can_be_provisioned(): void
    {
        foreach (['airline', 'hotel', 'insurance', 'esim'] as $type) {
            $provider = ProviderConfig::factory()->create(['type' => $type]);
            app(WalletProvisioningService::class)->provisionProviderWallet($provider);
            $this->assertNotNull($provider->getWallet("{$type}-provider"));
        }
    }

    /** @test */
    public function merchant_wallet_is_created_on_provisioning(): void
    {
        $merchant = Merchant::factory()->create();
        app(WalletProvisioningService::class)->provisionMerchantWallet($merchant);

        $wallet = $merchant->getWallet('merchant');
        $this->assertNotNull($wallet);
        $this->assertEquals('1120', $wallet->meta['ledger_account']);
    }

    /** @test */
    public function wallet_balance_starts_at_zero(): void
    {
        $agency = Agency::factory()->create();
        app(WalletProvisioningService::class)->provisionAgencyWallets($agency);

        $this->assertEquals(0, $agency->getWallet('operating')->balanceInt);
    }

    /** @test */
    public function deposit_to_provider_wallet_increases_balance(): void
    {
        $provider = ProviderConfig::factory()->create(['type' => 'airline']);
        app(WalletProvisioningService::class)->provisionProviderWallet($provider);

        $wallet = $provider->getWallet('airline-provider');
        $wallet->depositFloat(10000.000, ['tx_type' => 'deposit']);

        $this->assertEquals('10000.000', $wallet->balanceFloat);
    }

    /** @test */
    public function cannot_withdraw_more_than_provider_wallet_balance(): void
    {
        $provider = ProviderConfig::factory()->create(['type' => 'airline']);
        app(WalletProvisioningService::class)->provisionProviderWallet($provider);

        $wallet = $provider->getWallet('airline-provider');
        $wallet->depositFloat(500.000);

        $this->assertFalse($wallet->canWithdrawFloat(1000.000));
        $this->expectException(\Bavix\Wallet\Exceptions\BalanceIsEmpty::class);
        $wallet->withdrawFloat(1000.000);
    }
}
```

---

## Phase 4 — The Ledger Bridge (Core Integration Layer)

This is the most critical phase. The Ledger Bridge is the service that converts a wallet transaction event into a double-entry journal entry in abivia/ledger.

### 4.1 TransactionCreatedEventInterface Listener

```php
// App\Listeners\PostLedgerEntryOnWalletTransaction

use Bavix\Wallet\Internal\Events\TransactionCreatedEventInterface;

class PostLedgerEntryOnWalletTransaction
{
    public function __construct(
        private LedgerPostingService $ledgerService
    ) {}

    public function handle(TransactionCreatedEventInterface $event): void
    {
        $transaction = $event->getTransaction(); // bavix Transaction model
        $meta        = $transaction->meta ?? [];

        // Only post ledger entries for transactions that carry accounting metadata
        if (empty($meta['ledger_accounts'])) {
            return;
        }

        $this->ledgerService->postFromWalletTransaction($transaction);
    }
}
```

Register in `EventServiceProvider`:

```php
use Bavix\Wallet\Internal\Events\TransactionCreatedEventInterface;

protected $listen = [
    TransactionCreatedEventInterface::class => [
        PostLedgerEntryOnWalletTransaction::class,
    ],
];
```

### 4.2 LedgerPostingService

```php
// App\Services\Accounting\LedgerPostingService

class LedgerPostingService
{
    public function postFromWalletTransaction(Transaction $walletTx): LedgerEntry
    {
        $meta    = $walletTx->meta;
        $amount  = abs($walletTx->amountFloat);
        $orderId = $meta['order_id']   ?? null;
        $journal = $this->resolveJournal($meta['order_type'] ?? 'general');

        $entry = new JournalEntry();
        $entry->date        = now();
        $entry->description = $meta['tx_type'] ?? 'wallet_transaction';
        $entry->journal     = $journal;
        $entry->reference   = $orderId
            ? "order:{$orderId}|wallet_tx:{$walletTx->uuid}"
            : "wallet_tx:{$walletTx->uuid}";

        $entry->details = [
            [
                'account' => $meta['ledger_accounts']['debit'],
                'debit'   => $amount,
            ],
            [
                'account' => $meta['ledger_accounts']['credit'],
                'credit'  => $amount,
            ],
        ];

        return app(JournalEntryController::class)->create($entry);
    }

    // Multi-line entry for issuance (revenue + VAT + cost in one journal entry)
    public function postIssuanceEntry(IssuanceDTO $dto): LedgerEntry
    {
        $revenueNet = $dto->sellingPrice - $dto->vatAmount;
        $journal    = $this->resolveJournal($dto->productType);

        $entry = new JournalEntry();
        $entry->date        = now();
        $entry->description = "issuance:{$dto->productType}:order:{$dto->orderId}";
        $entry->journal     = $journal;
        $entry->reference   = "order:{$dto->orderId}|ref:{$dto->providerReference}";

        $entry->details = [
            // Customer payment received (debit cash/wallet)
            ['account' => '1110', 'debit'  => $dto->sellingPrice],

            // Revenue (credit — net of VAT)
            ['account' => $this->revenueAccount($dto->productType), 'credit' => $revenueNet],

            // VAT payable (credit)
            ['account' => '2400', 'credit' => $dto->vatAmount],

            // Provider cost (debit COGS)
            ['account' => $this->costAccount($dto->productType), 'debit'  => $dto->providerCost],

            // Provider wallet deducted (credit prepaid asset)
            ['account' => $this->providerWalletAccount($dto->productType), 'credit' => $dto->providerCost],
        ];

        return app(JournalEntryController::class)->create($entry);
    }

    // Reversal entry for cancellations/voids
    public function postReversalEntry(string $originalEntryReference, float $amount, string $productType, ?float $cancellationFee = null): void
    {
        // Post full reversal
        // If cancellation fee exists, post fee to 4700 and reverse only net amount
        // Implementation: mirror original entry with Dr/Cr swapped
        // If $cancellationFee > 0, add a line: Dr refund account, Cr 4700
    }

    private function resolveJournal(string $productType): string
    {
        return match($productType) {
            'airline'   => 'AIR',
            'hotel'     => 'HTL',
            'insurance' => 'INS',
            'esim'      => 'ESM',
            'settlement'=> 'STL',
            default     => 'GEN',
        };
    }

    private function revenueAccount(string $productType): string
    {
        return match($productType) {
            'airline'   => '4100',
            'hotel'     => '4200',
            'insurance' => '4300',
            'esim'      => '4400',
            default     => '4500',
        };
    }

    private function costAccount(string $productType): string
    {
        return match($productType) {
            'airline'   => '5100',
            'hotel'     => '5200',
            'insurance' => '5300',
            'esim'      => '5400',
            default     => '5500',
        };
    }

    private function providerWalletAccount(string $productType): string
    {
        return match($productType) {
            'airline'   => '1210',
            'hotel'     => '1220',
            'insurance' => '1230',
            'esim'      => '1240',
            default     => '1200',
        };
    }
}
```

### 4.3 IssuanceDTO

```php
// App\DTOs\IssuanceDTO

class IssuanceDTO
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $productType,     // airline|hotel|insurance|esim
        public readonly float  $sellingPrice,    // gross price charged to customer
        public readonly float  $vatAmount,       // VAT portion (0 if not applicable)
        public readonly float  $providerCost,    // cost charged by provider
        public readonly string $providerReference, // PNR / booking ref / policy number
        public readonly ?string $merchantId = null,  // set if merchant issuance
        public readonly ?float  $wholesalePrice = null, // merchant wholesale amount
    ) {}
}
```

---

## Phase 4 — Test Suite

```php
// tests/Tenant/Phase4/LedgerBridgeTest.php

class LedgerBridgeTest extends TenantTestCase
{
    /** @test */
    public function wallet_withdrawal_with_ledger_meta_creates_journal_entry(): void
    {
        $provider = $this->createProvisionedAirlineProvider();
        $provider->getWallet('airline-provider')->depositFloat(5000.000);

        $provider->getWallet('airline-provider')->withdrawFloat(950.000, [
            'order_id'   => 'ORD-001',
            'order_type' => 'airline',
            'tx_type'    => 'issuance',
            'ledger_accounts' => ['debit' => '5100', 'credit' => '1210'],
            'reference'  => 'PNR123',
        ]);

        // Journal entry was auto-posted by the event listener
        $this->assertDatabaseHas('journal_entry_details', [
            'account_code' => '5100',
        ], 'tenant');

        $this->assertDatabaseHas('journal_entry_details', [
            'account_code' => '1210',
        ], 'tenant');
    }

    /** @test */
    public function wallet_transaction_without_ledger_meta_does_not_create_journal_entry(): void
    {
        $agency = $this->createProvisionedAgency();
        $initialCount = DB::connection('tenant')->table('journal_entries')->count();

        $agency->getWallet('operating')->depositFloat(1000.000); // no meta

        $this->assertEquals($initialCount, DB::connection('tenant')->table('journal_entries')->count());
    }

    /** @test */
    public function issuance_journal_entry_is_balanced(): void
    {
        $dto = new IssuanceDTO(
            orderId:           'ORD-001',
            productType:       'airline',
            sellingPrice:      1200.000,
            vatAmount:         109.000,
            providerCost:      950.000,
            providerReference: 'PNR123',
        );

        $entry = app(LedgerPostingService::class)->postIssuanceEntry($dto);

        // Sum of debits must equal sum of credits
        $debits  = collect($entry->details)->sum('debit');
        $credits = collect($entry->details)->sum('credit');

        $this->assertEquals($debits, $credits, 'Journal entry must be balanced');
    }

    /** @test */
    public function issuance_entry_posts_to_correct_sub_journal(): void
    {
        $dto = new IssuanceDTO(
            orderId: 'ORD-002', productType: 'hotel',
            sellingPrice: 500.000, vatAmount: 45.000,
            providerCost: 400.000, providerReference: 'HTL-REF-001'
        );

        $entry = app(LedgerPostingService::class)->postIssuanceEntry($dto);

        $this->assertEquals('HTL', $entry->journal);
    }

    /** @test */
    public function journal_entry_reference_contains_order_id(): void
    {
        $dto = new IssuanceDTO(
            orderId: 'ORD-999', productType: 'airline',
            sellingPrice: 1000.000, vatAmount: 91.000,
            providerCost: 800.000, providerReference: 'PNR999'
        );

        $entry = app(LedgerPostingService::class)->postIssuanceEntry($dto);

        $this->assertStringContainsString('ORD-999', $entry->reference);
    }

    /** @test */
    public function reversal_entry_is_balanced_and_negates_original(): void
    {
        $dto = new IssuanceDTO('ORD-003', 'airline', 1200.000, 109.000, 950.000, 'PNR003');
        app(LedgerPostingService::class)->postIssuanceEntry($dto);

        app(LedgerPostingService::class)->postReversalEntry('order:ORD-003', 1200.000, 'airline');

        // After reversal, net position on revenue account should be zero
        $balance = app(LedgerQueryService::class)->accountBalance('4100');
        $this->assertEquals(0, $balance);
    }
}
```

---

## Phase 5 — Issuance Flow (Direct Agency)

### 5.1 DirectAgencyIssuanceService

This is the orchestrator for a direct agency ticket/product sale.

```php
// App\Services\Issuance\DirectAgencyIssuanceService

class DirectAgencyIssuanceService
{
    public function __construct(
        private ProviderApiService    $providerApi,
        private LedgerPostingService  $ledger,
        private OrderService          $orders,
    ) {}

    public function issue(DirectIssuanceRequest $request): Order
    {
        $providerWallet = $request->provider->getWallet("{$request->productType}-provider");
        $agencyWallet   = $request->agency->getWallet('operating');

        // Step 1: Validate provider wallet balance
        if (! $providerWallet->canWithdrawFloat($request->providerCost)) {
            throw new InsufficientProviderBalanceException();
        }

        // Step 2: Validate agency/customer balance (if tracked)
        if ($request->trackCustomerBalance && ! $agencyWallet->canWithdrawFloat($request->sellingPrice)) {
            throw new InsufficientCustomerBalanceException();
        }

        return DB::connection('tenant')->transaction(function () use ($request, $providerWallet, $agencyWallet) {

            // Step 3: Call provider API
            $providerRef = $this->providerApi->issue($request);

            // Step 4: Deduct provider wallet (event triggers ledger post for simple entries)
            $providerWallet->withdrawFloat($request->providerCost, [
                'order_id'        => null, // order not created yet — reference added in Step 6
                'order_type'      => $request->productType,
                'tx_type'         => 'issuance',
                'ledger_accounts' => [
                    'debit'  => $this->ledger->costAccount($request->productType),
                    'credit' => $this->ledger->providerWalletAccount($request->productType),
                ],
                'reference' => $providerRef,
            ]);

            // Step 5: Create order
            $order = $this->orders->create($request, $providerRef);

            // Step 6: Post full issuance ledger entry (revenue + VAT + cost in one balanced entry)
            $dto = new IssuanceDTO(
                orderId:           $order->id,
                productType:       $request->productType,
                sellingPrice:      $request->sellingPrice,
                vatAmount:         $request->vatAmount,
                providerCost:      $request->providerCost,
                providerReference: $providerRef,
            );
            $this->ledger->postIssuanceEntry($dto);

            return $order;
        });
    }
}
```

> **Atomicity Rule:** Steps 3–6 are wrapped in a single DB transaction. If the provider API call succeeds but any subsequent step fails, the transaction rolls back the wallet deduction and the journal entry together. The provider API call itself is outside the DB transaction (it's a network call), so implement an idempotency key on the provider API call and a compensation flow for the rare case of API success + DB failure.

---

## Phase 5 — Test Suite

```php
// tests/Tenant/Phase5/DirectAgencyIssuanceTest.php

class DirectAgencyIssuanceTest extends TenantTestCase
{
    /** @test */
    public function successful_airline_issuance_deducts_provider_wallet(): void
    {
        [$agency, $provider] = $this->setupDirectAgency('airline', 10000.000);

        $request = new DirectIssuanceRequest(
            agency: $agency, provider: $provider, productType: 'airline',
            sellingPrice: 1200.000, vatAmount: 109.000, providerCost: 950.000
        );

        app(DirectAgencyIssuanceService::class)->issue($request);

        $this->assertEquals('9050.000', $provider->getWallet('airline-provider')->balanceFloat);
    }

    /** @test */
    public function successful_issuance_creates_order(): void
    {
        [$agency, $provider] = $this->setupDirectAgency('airline', 10000.000);
        $request = $this->makeIssuanceRequest($agency, $provider);

        $order = app(DirectAgencyIssuanceService::class)->issue($request);

        $this->assertDatabaseHas('orders', ['id' => $order->id], 'tenant');
    }

    /** @test */
    public function successful_issuance_posts_balanced_journal_entry(): void
    {
        [$agency, $provider] = $this->setupDirectAgency('airline', 10000.000);
        $request = $this->makeIssuanceRequest($agency, $provider);

        app(DirectAgencyIssuanceService::class)->issue($request);

        $entries = DB::connection('tenant')->table('journal_entry_details')
            ->where('journal', 'AIR')->get();

        $debits  = $entries->sum('debit');
        $credits = $entries->sum('credit');

        $this->assertEquals($debits, $credits);
    }

    /** @test */
    public function issuance_fails_when_provider_wallet_has_insufficient_balance(): void
    {
        [$agency, $provider] = $this->setupDirectAgency('airline', 500.000); // only 500

        $request = new DirectIssuanceRequest(
            agency: $agency, provider: $provider, productType: 'airline',
            sellingPrice: 1200.000, vatAmount: 109.000, providerCost: 950.000 // costs 950
        );

        $this->expectException(InsufficientProviderBalanceException::class);
        app(DirectAgencyIssuanceService::class)->issue($request);

        // Wallet balance must be unchanged
        $this->assertEquals('500.000', $provider->getWallet('airline-provider')->balanceFloat);
    }

    /** @test */
    public function provider_wallet_is_not_deducted_if_provider_api_fails(): void
    {
        [$agency, $provider] = $this->setupDirectAgency('airline', 10000.000);
        $this->mockProviderApiToFail();

        $this->expectException(ProviderApiException::class);
        app(DirectAgencyIssuanceService::class)->issue($this->makeIssuanceRequest($agency, $provider));

        $this->assertEquals('10000.000', $provider->getWallet('airline-provider')->balanceFloat);
    }

    /** @test */
    public function issuance_revenue_is_recorded_net_of_vat(): void
    {
        [$agency, $provider] = $this->setupDirectAgency('airline', 10000.000);
        app(DirectAgencyIssuanceService::class)->issue($this->makeIssuanceRequest($agency, $provider));

        // Revenue account 4100 should show 1091 (1200 - 109 VAT)
        $revenue = app(LedgerQueryService::class)->accountBalance('4100');
        $this->assertEquals(1091.000, $revenue);

        // VAT payable 2400 should show 109
        $vat = app(LedgerQueryService::class)->accountBalance('2400');
        $this->assertEquals(109.000, $vat);
    }
}
```

---

## Phase 6 — Network Merchant Issuance

### 6.1 Architecture Recap

When Merchant M issues through Network Agency A:
- Merchant wallet lives in **Merchant tenant DB**
- Agency provider wallet lives in **Agency tenant DB**
- Both must be deducted in one logical operation
- Each tenant posts its own ledger entries

### 6.2 IssuanceBridgeService (Cross-Tenant)

```php
// App\Services\Issuance\IssuanceBridgeService

class IssuanceBridgeService
{
    public function deductBothTenants(
        string  $merchantTenantId,
        string  $agencyTenantId,
        float   $merchantDeductAmount,     // wholesale price
        float   $agencyProviderDeductAmount, // provider cost
        string  $productType,
        string  $orderId,
        string  $providerRef,
    ): void {
        // Deduct merchant wallet (run in merchant tenant context)
        $this->runInTenant($merchantTenantId, function () use (
            $merchantDeductAmount, $productType, $orderId, $providerRef, $agencyTenantId
        ) {
            $merchant = Merchant::first(); // resolved within tenant context
            $merchant->getWallet('merchant')->withdrawFloat($merchantDeductAmount, [
                'order_id'        => $orderId,
                'order_type'      => $productType,
                'tx_type'         => 'issuance',
                'ledger_accounts' => ['debit' => '5500', 'credit' => '1120'],
                'reference'       => $providerRef,
                'agency_tenant_id'=> $agencyTenantId,
            ]);
        });

        // Deduct agency provider wallet (run in agency tenant context)
        $this->runInTenant($agencyTenantId, function () use (
            $agencyProviderDeductAmount, $productType, $orderId, $providerRef
        ) {
            $provider = $this->resolveProvider($productType);
            $provider->getWallet("{$productType}-provider")->withdrawFloat($agencyProviderDeductAmount, [
                'order_id'        => $orderId,
                'order_type'      => $productType,
                'tx_type'         => 'issuance',
                'ledger_accounts' => [
                    'debit'  => $this->costAccount($productType),
                    'credit' => $this->providerWalletAccount($productType),
                ],
                'reference' => $providerRef,
            ]);
        });
    }

    private function runInTenant(string $tenantId, callable $callback): void
    {
        $tenant = Tenant::find($tenantId);
        tenancy()->initialize($tenant);
        $callback();
        tenancy()->end();
    }
}
```

### 6.3 MerchantIssuanceService

```php
class MerchantIssuanceService
{
    public function issue(MerchantIssuanceRequest $request): Order
    {
        // Step 1: Validate merchant wallet balance
        if (! $request->merchantWallet->canWithdrawFloat($request->wholesalePrice)) {
            throw new InsufficientMerchantBalanceException();
        }

        // Step 2: Validate agency provider wallet balance (read from central allocation)
        if (! $this->validateAgencyProviderBalance($request)) {
            throw new InsufficientProviderBalanceException();
        }

        // Step 3: Call provider API (using agency credentials)
        $providerRef = $this->providerApi->issueViaAgency($request);

        // Step 4: Deduct both wallets (cross-tenant)
        $this->bridge->deductBothTenants(
            merchantTenantId:          $request->merchantTenantId,
            agencyTenantId:            $request->agencyTenantId,
            merchantDeductAmount:      $request->wholesalePrice,
            agencyProviderDeductAmount:$request->providerCost,
            productType:               $request->productType,
            orderId:                   $request->orderId,
            providerRef:               $providerRef,
        );

        // Step 5: Create merchant order (in merchant tenant DB)
        $order = $this->createMerchantOrder($request, $providerRef);

        // Step 6: Post merchant ledger entries (merchant books)
        $this->postMerchantLedgerEntries($request, $order);

        // Step 7: Post agency ledger entries (agency books — run in agency tenant context)
        $this->postAgencyNetworkLedgerEntries($request, $order);

        return $order;
    }

    private function postMerchantLedgerEntries(MerchantIssuanceRequest $request, Order $order): void
    {
        // Merchant books: full gross revenue + wholesale cost payable to agency
        $dto = new IssuanceDTO(
            orderId:           $order->id,
            productType:       $request->productType,
            sellingPrice:      $request->sellingPrice,
            vatAmount:         $request->vatAmount,
            providerCost:      $request->wholesalePrice, // merchant's cost = wholesale price
            providerReference: $order->provider_reference,
            merchantId:        $request->merchantId,
            wholesalePrice:    $request->wholesalePrice,
        );
        app(LedgerPostingService::class)->postMerchantIssuanceEntry($dto);
        // Posts: Dr 1120 merchant wallet | Cr 4100 revenue + Cr 2400 VAT
        //        Dr 5500 wholesale cost  | Cr 2200 network agency payable
    }

    private function postAgencyNetworkLedgerEntries(MerchantIssuanceRequest $request, Order $order): void
    {
        tenancy()->initialize(Tenant::find($request->agencyTenantId));

        app(LedgerPostingService::class)->postAgencyNetworkEntry([
            'order_id'         => $order->id,
            'product_type'     => $request->productType,
            'wholesale_price'  => $request->wholesalePrice,
            'provider_cost'    => $request->providerCost,
            'commission'       => $request->wholesalePrice - $request->providerCost,
            'merchant_tenant'  => $request->merchantTenantId,
        ]);
        // Posts: Dr 1320 merchant receivable | Cr 4600 network commission + Cr 7200 settlement clearing
        //        Dr 5100 provider cost         | Cr 1210 provider wallet

        tenancy()->end();
    }
}
```

---

## Phase 6 — Test Suite

```php
// tests/Tenant/Phase6/MerchantIssuanceTest.php

class MerchantIssuanceTest extends TenantTestCase
{
    /** @test */
    public function merchant_wallet_is_deducted_on_issuance(): void
    {
        [$merchantTenant, $agencyTenant] = $this->setupNetworkRelationship('airline');
        $this->fundMerchantWallet($merchantTenant, 5000.000);
        $this->fundAgencyProviderWallet($agencyTenant, 'airline', 10000.000);

        $request = $this->makeMerchantIssuanceRequest(
            merchantTenant: $merchantTenant, agencyTenant: $agencyTenant,
            productType: 'airline', sellingPrice: 1200.000,
            vatAmount: 109.000, wholesalePrice: 1000.000, providerCost: 950.000
        );

        app(MerchantIssuanceService::class)->issue($request);

        $balance = $this->getMerchantWalletBalance($merchantTenant);
        $this->assertEquals('4000.000', $balance); // 5000 - 1000
    }

    /** @test */
    public function agency_provider_wallet_is_deducted_on_merchant_issuance(): void
    {
        [$merchantTenant, $agencyTenant] = $this->setupNetworkRelationship('airline');
        $this->fundMerchantWallet($merchantTenant, 5000.000);
        $this->fundAgencyProviderWallet($agencyTenant, 'airline', 10000.000);

        app(MerchantIssuanceService::class)->issue($this->makeMerchantIssuanceRequest(
            $merchantTenant, $agencyTenant, 'airline', 1200.000, 109.000, 1000.000, 950.000
        ));

        $balance = $this->getAgencyProviderWalletBalance($agencyTenant, 'airline');
        $this->assertEquals('9050.000', $balance); // 10000 - 950
    }

    /** @test */
    public function merchant_books_record_full_gross_revenue(): void
    {
        [$merchantTenant, $agencyTenant] = $this->setupNetworkRelationship('airline');
        $this->fundWallets($merchantTenant, $agencyTenant);

        app(MerchantIssuanceService::class)->issue($this->makeMerchantIssuanceRequest(
            $merchantTenant, $agencyTenant, 'airline', 1200.000, 109.000, 1000.000, 950.000
        ));

        $revenue = $this->runInTenant($merchantTenant, fn() =>
            app(LedgerQueryService::class)->accountBalance('4100')
        );
        $this->assertEquals(1091.000, $revenue); // 1200 - 109 VAT
    }

    /** @test */
    public function merchant_books_record_network_agency_payable(): void
    {
        [$merchantTenant, $agencyTenant] = $this->setupNetworkRelationship('airline');
        $this->fundWallets($merchantTenant, $agencyTenant);

        app(MerchantIssuanceService::class)->issue($this->makeMerchantIssuanceRequest(
            $merchantTenant, $agencyTenant, 'airline', 1200.000, 109.000, 1000.000, 950.000
        ));

        $payable = $this->runInTenant($merchantTenant, fn() =>
            app(LedgerQueryService::class)->accountBalance('2200')
        );
        $this->assertEquals(1000.000, $payable);
    }

    /** @test */
    public function agency_books_record_merchant_receivable_and_commission(): void
    {
        [$merchantTenant, $agencyTenant] = $this->setupNetworkRelationship('airline');
        $this->fundWallets($merchantTenant, $agencyTenant);

        app(MerchantIssuanceService::class)->issue($this->makeMerchantIssuanceRequest(
            $merchantTenant, $agencyTenant, 'airline', 1200.000, 109.000, 1000.000, 950.000
        ));

        $commission = $this->runInTenant($agencyTenant, fn() =>
            app(LedgerQueryService::class)->accountBalance('4600')
        );
        $this->assertEquals(50.000, $commission); // 1000 - 950
    }

    /** @test */
    public function issuance_fails_if_merchant_wallet_insufficient_and_neither_wallet_is_touched(): void
    {
        [$merchantTenant, $agencyTenant] = $this->setupNetworkRelationship('airline');
        $this->fundMerchantWallet($merchantTenant, 100.000); // not enough
        $this->fundAgencyProviderWallet($agencyTenant, 'airline', 10000.000);

        $this->expectException(InsufficientMerchantBalanceException::class);
        app(MerchantIssuanceService::class)->issue($this->makeMerchantIssuanceRequest(
            $merchantTenant, $agencyTenant, 'airline', 1200.000, 109.000, 1000.000, 950.000
        ));

        $this->assertEquals('100.000', $this->getMerchantWalletBalance($merchantTenant));
        $this->assertEquals('10000.000', $this->getAgencyProviderWalletBalance($agencyTenant, 'airline'));
    }
}
```

---

## Phase 7 — Cancellations, Voids & Refunds

### 7.1 CancellationService

```php
class CancellationService
{
    public function cancel(Order $order, ?float $cancellationFee = null): void
    {
        DB::connection('tenant')->transaction(function () use ($order, $cancellationFee) {

            // Step 1: Call provider cancellation API
            $this->providerApi->cancel($order->provider_reference);

            // Step 2: Reverse provider wallet (only if provider restores balance)
            if ($this->providerRestoresBalance($order)) {
                $providerWallet = $this->resolveProviderWallet($order);
                $providerWallet->depositFloat($order->providerCost, [
                    'order_id'        => $order->id,
                    'order_type'      => $order->product_type,
                    'tx_type'         => 'refund',
                    'ledger_accounts' => [
                        'debit'  => $this->ledger->providerWalletAccount($order->product_type),
                        'credit' => $this->ledger->costAccount($order->product_type),
                    ],
                    'reference' => "cancel:{$order->provider_reference}",
                ]);
            }

            // Step 3: Reverse customer/agency wallet
            $refundAmount = $order->sellingPrice - ($cancellationFee ?? 0);
            $agencyWallet = $order->agency->getWallet('operating');
            $agencyWallet->depositFloat($refundAmount, [
                'order_id'        => $order->id,
                'order_type'      => $order->product_type,
                'tx_type'         => 'refund',
                'ledger_accounts' => [
                    'debit'  => '1110',
                    'credit' => '4100', // reverse revenue
                ],
            ]);

            // Step 4: Post reversal ledger entry
            $this->ledger->postReversalEntry(
                originalEntryReference: "order:{$order->id}",
                amount:                 $order->sellingPrice,
                productType:            $order->product_type,
                cancellationFee:        $cancellationFee,
            );

            // Step 5: Update order status
            $order->update(['status' => 'cancelled', 'cancellation_fee' => $cancellationFee]);
        });
    }
}
```

---

## Phase 7 — Test Suite

```php
// tests/Tenant/Phase7/CancellationTest.php

class CancellationTest extends TenantTestCase
{
    /** @test */
    public function cancellation_without_fee_fully_reverses_provider_wallet(): void
    {
        $order = $this->createIssuedOrder('airline', 1200.000, 109.000, 950.000);
        $providerBalanceBefore = $this->getProviderBalance($order, 'airline');

        app(CancellationService::class)->cancel($order);

        $this->assertEquals($providerBalanceBefore, $this->getProviderBalance($order, 'airline'));
    }

    /** @test */
    public function cancellation_without_fee_reverses_revenue_to_zero(): void
    {
        $order = $this->createIssuedOrder('airline', 1200.000, 109.000, 950.000);

        app(CancellationService::class)->cancel($order);

        $revenue = app(LedgerQueryService::class)->accountBalance('4100');
        $this->assertEquals(0, $revenue);
    }

    /** @test */
    public function cancellation_with_fee_retains_fee_in_account_4700(): void
    {
        $order = $this->createIssuedOrder('airline', 1200.000, 109.000, 950.000);

        app(CancellationService::class)->cancel($order, cancellationFee: 50.000);

        $feeIncome = app(LedgerQueryService::class)->accountBalance('4700');
        $this->assertEquals(50.000, $feeIncome);
    }

    /** @test */
    public function cancellation_with_fee_reverses_only_net_amount(): void
    {
        $order = $this->createIssuedOrder('airline', 1200.000, 109.000, 950.000);

        app(CancellationService::class)->cancel($order, cancellationFee: 50.000);

        // Customer refunded 1200 - 50 = 1150
        $refund = app(LedgerQueryService::class)->walletTxAmount($order->id, 'refund');
        $this->assertEquals(1150.000, $refund);
    }

    /** @test */
    public function cancelled_order_status_is_updated(): void
    {
        $order = $this->createIssuedOrder('airline', 1200.000, 109.000, 950.000);
        app(CancellationService::class)->cancel($order);

        $this->assertEquals('cancelled', $order->fresh()->status);
    }

    /** @test */
    public function journal_entries_remain_balanced_after_cancellation(): void
    {
        $order = $this->createIssuedOrder('airline', 1200.000, 109.000, 950.000);
        app(CancellationService::class)->cancel($order);

        $all = DB::connection('tenant')->table('journal_entry_details')->get();
        $this->assertEquals($all->sum('debit'), $all->sum('credit'));
    }
}
```

---

## Phase 8 — Settlement

### 8.1 SettlementService

```php
class SettlementService
{
    // Settle what Merchant M owes Network Agency A
    public function settleMerchantToAgency(
        string $merchantTenantId,
        string $agencyTenantId,
        float  $amount,
        string $batchReference,
    ): void {
        // Merchant side: clear payable
        $this->runInTenant($merchantTenantId, function () use ($amount, $batchReference, $agencyTenantId) {
            $merchantWallet = Merchant::first()->getWallet('merchant');
            $merchantWallet->withdrawFloat($amount, [
                'order_type'      => 'settlement',
                'tx_type'         => 'settlement',
                'ledger_accounts' => ['debit' => '2200', 'credit' => '1120'],
                'reference'       => $batchReference,
                'agency_tenant'   => $agencyTenantId,
            ]);
        });

        // Agency side: receive settlement
        $this->runInTenant($agencyTenantId, function () use ($amount, $batchReference, $merchantTenantId) {
            $agencyWallet = Agency::first()->getWallet('operating');
            $agencyWallet->depositFloat($amount, [
                'order_type'      => 'settlement',
                'tx_type'         => 'settlement',
                'ledger_accounts' => ['debit' => '1110', 'credit' => '1320'],
                'reference'       => $batchReference,
                'merchant_tenant' => $merchantTenantId,
            ]);
        });
    }
}
```

---

## Phase 8 — Test Suite

```php
// tests/Tenant/Phase8/SettlementTest.php

class SettlementTest extends TenantTestCase
{
    /** @test */
    public function settlement_clears_merchant_network_agency_payable(): void
    {
        [$merchantTenant, $agencyTenant] = $this->setupPostIssuancePair();

        app(SettlementService::class)->settleMerchantToAgency(
            $merchantTenant->id, $agencyTenant->id, 1000.000, 'BATCH-001'
        );

        $payable = $this->runInTenant($merchantTenant, fn() =>
            app(LedgerQueryService::class)->accountBalance('2200')
        );
        $this->assertEquals(0, $payable);
    }

    /** @test */
    public function settlement_clears_agency_merchant_receivable(): void
    {
        [$merchantTenant, $agencyTenant] = $this->setupPostIssuancePair();

        app(SettlementService::class)->settleMerchantToAgency(
            $merchantTenant->id, $agencyTenant->id, 1000.000, 'BATCH-001'
        );

        $receivable = $this->runInTenant($agencyTenant, fn() =>
            app(LedgerQueryService::class)->accountBalance('1320')
        );
        $this->assertEquals(0, $receivable);
    }

    /** @test */
    public function settlement_journal_entry_is_balanced_on_both_sides(): void
    {
        [$merchantTenant, $agencyTenant] = $this->setupPostIssuancePair();

        app(SettlementService::class)->settleMerchantToAgency(
            $merchantTenant->id, $agencyTenant->id, 1000.000, 'BATCH-001'
        );

        // Check merchant tenant
        $merchantEntries = $this->runInTenant($merchantTenant, fn() =>
            DB::table('journal_entry_details')
                ->where('journal', 'STL')->get()
        );
        $this->assertEquals($merchantEntries->sum('debit'), $merchantEntries->sum('credit'));

        // Check agency tenant
        $agencyEntries = $this->runInTenant($agencyTenant, fn() =>
            DB::table('journal_entry_details')
                ->where('journal', 'STL')->get()
        );
        $this->assertEquals($agencyEntries->sum('debit'), $agencyEntries->sum('credit'));
    }
}
```

---

## Phase 9 — Reports

### 9.1 Required Reports per Tenant

Implement as Laravel `Reportable` services, returning structured arrays or collections for rendering.

| Report Class | Source | Key Output |
|---|---|---|
| `WalletLedgerReconciliationReport` | wallet transactions + ledger entries | wallet balance vs ledger balance per account |
| `ProviderWalletReport` | provider wallet transactions | balance history, deductions, deposits per provider |
| `RevenueByProductReport` | ledger accounts 4100–4400 | revenue per product type, period comparison |
| `GrossMarginReport` | 4000 revenue – 5000 cost | gross profit, margin %, per product type |
| `MerchantSettlementAgingReport` | account 1320 / 2200 | aged outstanding balances, days outstanding |
| `VATSummaryReport` | account 2400 | VAT collected, period, filing-ready |
| `CancellationVoidAuditReport` | orders with status=cancelled + reversal journal entries | audit trail |
| `TrialBalanceReport` | abivia/ledger native | all accounts, debit/credit totals, net balance |

### 9.2 WalletLedgerReconciliationService

```php
class WalletLedgerReconciliationService
{
    public function reconcile(): array
    {
        $walletAccounts = [
            '1110' => ['model' => Agency::class,       'wallet' => 'operating'],
            '1120' => ['model' => Merchant::class,     'wallet' => 'merchant'],
            '1210' => ['model' => ProviderConfig::class,'wallet' => 'airline-provider'],
            '1220' => ['model' => ProviderConfig::class,'wallet' => 'hotel-provider'],
            '1230' => ['model' => ProviderConfig::class,'wallet' => 'insurance-provider'],
            '1240' => ['model' => ProviderConfig::class,'wallet' => 'esim-provider'],
        ];

        $results = [];
        foreach ($walletAccounts as $accountCode => $cfg) {
            $walletBalance = $this->getWalletBalance($cfg);
            $ledgerBalance = app(LedgerQueryService::class)->accountBalance($accountCode);
            $results[$accountCode] = [
                'wallet_balance' => $walletBalance,
                'ledger_balance' => $ledgerBalance,
                'difference'     => abs($walletBalance - $ledgerBalance),
                'status'         => $walletBalance === $ledgerBalance ? 'matched' : 'MISMATCH',
            ];
        }
        return $results;
    }
}
```

---

## Phase 9 — Test Suite

```php
// tests/Tenant/Phase9/ReportsTest.php

class ReportsTest extends TenantTestCase
{
    /** @test */
    public function wallet_ledger_reconciliation_shows_matched_after_issuance(): void
    {
        $this->performDirectIssuance('airline', 1200.000, 109.000, 950.000);

        $reconciliation = app(WalletLedgerReconciliationService::class)->reconcile();

        $this->assertEquals('matched', $reconciliation['1210']['status']);
    }

    /** @test */
    public function wallet_ledger_reconciliation_detects_mismatch(): void
    {
        // Simulate a wallet deduction without a ledger entry (data corruption)
        $provider = $this->createProvisionedProvider('airline', 5000.000);
        $provider->getWallet('airline-provider')->withdrawFloat(500.000); // no meta = no ledger entry

        $reconciliation = app(WalletLedgerReconciliationService::class)->reconcile();

        $this->assertEquals('MISMATCH', $reconciliation['1210']['status']);
    }

    /** @test */
    public function trial_balance_is_balanced_after_full_issuance(): void
    {
        $this->performDirectIssuance('airline', 1200.000, 109.000, 950.000);

        $trialBalance = app(TrialBalanceReport::class)->generate();
        $totalDebits  = collect($trialBalance)->sum('debit');
        $totalCredits = collect($trialBalance)->sum('credit');

        $this->assertEquals($totalDebits, $totalCredits);
    }

    /** @test */
    public function revenue_report_shows_correct_product_breakdown(): void
    {
        $this->performDirectIssuance('airline',   1200.000, 109.000, 950.000);
        $this->performDirectIssuance('hotel',     500.000,  45.000,  400.000);
        $this->performDirectIssuance('insurance', 200.000,  18.000,  150.000);

        $report = app(RevenueByProductReport::class)->generate();

        $this->assertEqualsWithDelta(1091.000, $report['airline'], 0.001);
        $this->assertEqualsWithDelta(455.000,  $report['hotel'],   0.001);
        $this->assertEqualsWithDelta(182.000,  $report['insurance'], 0.001);
    }

    /** @test */
    public function merchant_settlement_aging_shows_outstanding_receivable(): void
    {
        $this->performMerchantIssuance('airline', 1200.000, 109.000, 1000.000, 950.000);

        $aging = app(MerchantSettlementAgingReport::class)->generate();

        $this->assertGreaterThan(0, $aging->where('status', 'outstanding')->sum('amount'));
    }
}
```

---

## Summary: Phase Execution Order

| Phase | What Gets Built | Cannot Start Until |
|---|---|---|
| 1 | Package install, tenant migration pipeline | — |
| 2 | Chart of accounts + bootstrap service | Phase 1 ✓ |
| 3 | Wallet models + provisioning service | Phase 1 ✓ |
| 4 | Ledger Bridge (event listener + posting service) | Phases 2 + 3 ✓ |
| 5 | Direct Agency issuance flow | Phase 4 ✓ |
| 6 | Network Merchant issuance + cross-tenant bridge | Phase 5 ✓ |
| 7 | Cancellations, voids, refunds | Phase 5 ✓ |
| 8 | Settlement service | Phase 6 ✓ |
| 9 | Reports + reconciliation | Phases 5–8 ✓ |

---

## Critical Rules (AI Agent Must Follow)

1. **Never run wallet or ledger migrations on the central database.**
2. **Every wallet withdrawal/deposit that affects financial position must carry `ledger_accounts` in its meta array.** Deposits for top-ups and internal transfers may omit it.
3. **All journal entries must balance** — sum of debits must equal sum of credits before posting.
4. **Provider API calls are always outside the DB transaction.** Wallet and ledger operations are always inside.
5. **Revenue is recognized at provider confirmation**, not at booking and not at payment.
6. **Revenue is always recorded net of VAT.** VAT goes to account 2400.
7. **Cross-tenant wallet operations must switch the tenant context** using `tenancy()->initialize()` and `tenancy()->end()`. Never assume a shared DB connection.
8. **Cancellation reversals must be posted as new journal entries**, not by deleting original entries. The abivia/ledger `lock()` feature should lock entries after monthly close.
9. **The trial balance must be run before monthly close.** Any mismatch between wallet balance and corresponding ledger account (from the reconciliation report) must be resolved before close.
10. **Merchant gross revenue is the full selling price to the customer.** Network agency revenue is commission/margin only.