# Booknow — Accounting Section UI Implementation Plan

**Stack:** Laravel · Inertia.js · React · Tailwind CSS · Shadcn/UI  
**Section:** `/accounting/*`  
**Users:** Agency owner/finance manager · Accountant/bookkeeper · Merchant manager  
**Aligned with:** Booknow Accounting Implementation Plan (Phases 1–9)  

---

## How to Use This Document

Hand this to the AI agent one page at a time. Each page definition includes:
- Route and file path
- Purpose and user roles
- Data sources (which backend service/API feeds the page)
- Component breakdown (exact Shadcn/UI components to use)
- UI behaviour and interactions
- A test checklist the agent must verify before marking the page done

All pages live under the `/accounting` route group and are only accessible to users with the `accounting.view` permission. Write-level actions require `accounting.manage`.

---

## Section Architecture

```
resources/js/Pages/Accounting/
├── Dashboard.tsx                   ← Accounting home / overview
├── Wallets/
│   ├── Index.tsx                   ← All wallets list
│   ├── Show.tsx                    ← Single wallet ledger + transactions
│   └── Deposit.tsx                 ← Fund a wallet
├── Providers/
│   ├── Index.tsx                   ← Provider wallet overview
│   └── Show.tsx                    ← Single provider wallet detail
├── Ledger/
│   ├── JournalEntries.tsx          ← Journal entries log
│   ├── TrialBalance.tsx            ← Trial balance report
│   ├── ChartOfAccounts.tsx         ← CoA viewer
│   └── AccountDetail.tsx           ← Drill into a single account
├── Issuance/
│   └── History.tsx                 ← All issuances with wallet+ledger links
├── Settlement/
│   ├── Index.tsx                   ← Settlement overview
│   ├── MerchantAging.tsx           ← Merchant receivables aging
│   └── BatchDetail.tsx             ← Single settlement batch
├── Cancellations/
│   └── Index.tsx                   ← Cancellation & void audit log
├── Reports/
│   ├── Index.tsx                   ← Reports hub
│   ├── Revenue.tsx                 ← Revenue by product
│   ├── GrossMargin.tsx             ← Gross margin report
│   ├── VATSummary.tsx              ← VAT / tax report
│   └── Reconciliation.tsx          ← Wallet vs ledger reconciliation
└── Settings/
    └── Index.tsx                   ← Accounting preferences
```

---

## Inertia Route Group

```php
// routes/accounting.php — loaded inside the tenant middleware group

Route::prefix('accounting')
    ->middleware(['auth', 'verified', 'permission:accounting.view'])
    ->name('accounting.')
    ->group(function () {

    Route::get('/',                         [AccountingDashboardController::class, 'index'])->name('dashboard');

    // Wallets
    Route::get('/wallets',                  [WalletController::class, 'index'])->name('wallets.index');
    Route::get('/wallets/{wallet}',         [WalletController::class, 'show'])->name('wallets.show');
    Route::post('/wallets/{wallet}/deposit',[WalletController::class, 'deposit'])
        ->middleware('permission:accounting.manage')->name('wallets.deposit');

    // Provider wallets
    Route::get('/providers',               [ProviderWalletController::class, 'index'])->name('providers.index');
    Route::get('/providers/{provider}',    [ProviderWalletController::class, 'show'])->name('providers.show');

    // Ledger
    Route::get('/ledger/journal',          [LedgerController::class, 'journalEntries'])->name('ledger.journal');
    Route::get('/ledger/trial-balance',    [LedgerController::class, 'trialBalance'])->name('ledger.trial-balance');
    Route::get('/ledger/chart-of-accounts',[LedgerController::class, 'chartOfAccounts'])->name('ledger.coa');
    Route::get('/ledger/accounts/{code}',  [LedgerController::class, 'accountDetail'])->name('ledger.account');

    // Issuance history
    Route::get('/issuances',               [IssuanceHistoryController::class, 'index'])->name('issuances.index');

    // Settlement
    Route::get('/settlement',              [SettlementController::class, 'index'])->name('settlement.index');
    Route::get('/settlement/aging',        [SettlementController::class, 'aging'])->name('settlement.aging');
    Route::get('/settlement/{batch}',      [SettlementController::class, 'batch'])->name('settlement.batch');
    Route::post('/settlement/run',         [SettlementController::class, 'run'])
        ->middleware('permission:accounting.manage')->name('settlement.run');

    // Cancellations
    Route::get('/cancellations',           [CancellationController::class, 'index'])->name('cancellations.index');

    // Reports
    Route::get('/reports',                 [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/revenue',         [ReportController::class, 'revenue'])->name('reports.revenue');
    Route::get('/reports/gross-margin',    [ReportController::class, 'grossMargin'])->name('reports.gross-margin');
    Route::get('/reports/vat',             [ReportController::class, 'vat'])->name('reports.vat');
    Route::get('/reports/reconciliation',  [ReportController::class, 'reconciliation'])->name('reports.reconciliation');

    // Settings
    Route::get('/settings',                [AccountingSettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings',                [AccountingSettingsController::class, 'update'])
        ->middleware('permission:accounting.manage')->name('settings.update');
});
```

---

## Shared Layout & Navigation

### Accounting Shell Component
`resources/js/Layouts/AccountingLayout.tsx`

Build a persistent left sidebar specific to the Accounting section. It sits inside the main app shell.

**Sidebar sections and links:**

```
Overview
  └── Dashboard

Wallets & Balances
  ├── All Wallets
  └── Provider Wallets

Ledger
  ├── Journal Entries
  ├── Trial Balance
  └── Chart of Accounts

Operations
  ├── Issuance History
  ├── Settlement
  └── Cancellations & Voids

Reports
  ├── Revenue
  ├── Gross Margin
  ├── VAT Summary
  └── Reconciliation

Settings
  └── Accounting Preferences
```

**Components:**
- `SidebarNav` — Shadcn `ScrollArea` wrapping a nav list with active state highlight
- `AccountingBreadcrumb` — Shadcn `Breadcrumb` at the top of every page
- `PageHeader` — title + subtitle + optional action button slot

**Behaviour:**
- Active link highlighted with a left border accent
- Sidebar collapses to icon-only on mobile (Shadcn `Sheet`)
- Breadcrumb updates on every route change

---

## Page 1 — Accounting Dashboard

**Route:** `GET /accounting`  
**File:** `Pages/Accounting/Dashboard.tsx`  
**Role access:** All three roles  

### Purpose
Single-screen overview of the agency's financial position. The user lands here first and should be able to answer: "What is my balance? Did anything go wrong? What do I owe and what is owed to me?"

### Data (Inertia props from `AccountingDashboardController`)

```ts
interface DashboardProps {
  walletSummary: {
    operatingBalance: number;
    providerWallets: { name: string; slug: string; balance: number; currency: string }[];
    merchantWallet?: number; // only for merchant agencies
  };
  period: { from: string; to: string }; // current month default
  revenue: { total: number; byProduct: Record<string, number> };
  costOfSales: number;
  grossMargin: number;
  grossMarginPct: number;
  vatPayable: number;
  outstandingReceivables: number;  // account 1310 + 1320
  outstandingPayables: number;     // account 2200
  recentJournalEntries: JournalEntryRow[];
  reconciliationStatus: 'all_matched' | 'has_mismatches';
  alerts: Alert[]; // low provider balance, unreconciled entries, etc.
}
```

### Layout

```
┌─────────────────────────────────────────────────────────────┐
│  [Alert bar — shown only if alerts.length > 0]              │
├──────────────┬──────────────┬──────────────┬────────────────┤
│ Operating    │ Total        │ Gross        │ VAT            │
│ Wallet       │ Revenue      │ Margin       │ Payable        │
│ Balance      │ (this month) │ %            │ (this month)   │
├──────────────┴──────────────┴──────────────┴────────────────┤
│  Provider Wallet Balances (horizontal scroll cards)         │
│  [Airline] [Hotel] [Insurance] [eSIM]                       │
├─────────────────────────────┬───────────────────────────────┤
│  Revenue by Product         │  Outstanding                  │
│  (Recharts BarChart)        │  Receivables / Payables       │
├─────────────────────────────┴───────────────────────────────┤
│  Recent Journal Entries (last 10, with link to full log)    │
└─────────────────────────────────────────────────────────────┘
```

### Components

| Element | Shadcn/UI Component | Notes |
|---|---|---|
| Alert bar | `Alert` with `AlertTriangle` icon | Only renders when `alerts.length > 0` |
| KPI cards | `Card` → `CardHeader` + `CardContent` | 4-column grid on desktop, 2-column on mobile |
| Provider wallet cards | `Card` with balance + color-coded low-balance warning | Horizontal `ScrollArea` |
| Revenue chart | `Recharts BarChart` inside a `Card` | Grouped by product type, current vs previous month |
| Receivables/Payables | `Card` with two `Badge`-highlighted numbers | Link to Settlement > Aging |
| Recent entries table | `Table` inside `Card` | 10 rows, columns: date, description, journal, debit, credit |
| Reconciliation badge | `Badge` variant success/destructive | Links to Reports > Reconciliation if mismatch |

### Behaviour
- Period selector (this month / last month / custom range) at top right — updates all widgets via Inertia `router.get` with query params
- Provider wallet card shows a red `Badge` "Low Balance" if balance < configurable threshold
- "Run Reconciliation" button visible only to `accounting.manage` role — triggers a background job and refreshes the status badge

### Test Checklist
- [ ] All four KPI cards render with correct values
- [ ] Provider wallet cards render one card per configured provider
- [ ] Revenue bar chart renders without crashing when `byProduct` has zero values
- [ ] Alert bar is hidden when `alerts` is empty
- [ ] Alert bar renders correctly when one or more alerts exist
- [ ] Period selector changes update all displayed figures
- [ ] Reconciliation badge shows green when status is `all_matched`
- [ ] Reconciliation badge shows red when status is `has_mismatches`
- [ ] Recent journal entries table shows max 10 rows with "View All" link
- [ ] Page is accessible to agency owner and accountant roles
- [ ] Page is inaccessible without `accounting.view` permission (redirects to 403)

---

## Page 2 — All Wallets

**Route:** `GET /accounting/wallets`  
**File:** `Pages/Accounting/Wallets/Index.tsx`  
**Role access:** All three roles  

### Purpose
Complete list of all wallets belonging to this tenant — operating wallet, provider wallets, and merchant wallet if applicable. Each row links to the wallet's transaction history.

### Data

```ts
interface WalletsIndexProps {
  wallets: {
    id: string;
    name: string;
    slug: string;
    type: 'operating' | 'provider' | 'merchant';
    balance: number;
    currency: string;
    ledgerAccount: string; // e.g. "1110"
    lastActivityAt: string;
    transactionCount: number;
  }[];
}
```

### Layout
- `PageHeader` with title "Wallets" and optional "Fund Wallet" button (role-gated)
- `Table` with sortable columns: Wallet Name · Type · Ledger Account · Balance · Last Activity · Transactions · Actions
- Each row has a "View" action that navigates to `Wallets/Show`
- Type column uses `Badge`: `operating` → blue, `provider` → purple, `merchant` → amber

### Test Checklist
- [ ] All wallets for the tenant are listed
- [ ] Type badges render with correct colours
- [ ] Ledger account code is shown for every wallet
- [ ] Clicking "View" navigates to the correct wallet detail page
- [ ] Balance is formatted with correct currency and decimal places (3 for LYD)
- [ ] Empty state renders if no wallets exist

---

## Page 3 — Wallet Detail

**Route:** `GET /accounting/wallets/{wallet}`  
**File:** `Pages/Accounting/Wallets/Show.tsx`  
**Role access:** All three roles  

### Purpose
Full transaction history for a single wallet with balance graph, and a direct link from each transaction to its corresponding journal entry.

### Data

```ts
interface WalletShowProps {
  wallet: { id: string; name: string; slug: string; balance: number; currency: string; ledgerAccount: string };
  transactions: Paginated<{
    id: string;
    uuid: string;
    type: 'deposit' | 'withdraw';
    amount: number;
    balanceAfter: number;
    meta: Record<string, any>;
    confirmedAt: string;
    createdAt: string;
    journalEntryReference?: string; // links to ledger
    orderReference?: string;
  }>;
  balanceHistory: { date: string; balance: number }[]; // last 30 days
}
```

### Layout

```
┌─────────────────────────────────────────────────────────────┐
│  Wallet name + ledger account code badge    [Fund Wallet ↓] │
│  Current balance (large)                                     │
├─────────────────────────────────────────────────────────────┤
│  Balance over last 30 days (Recharts AreaChart)             │
├─────────────────────────────────────────────────────────────┤
│  Transactions table (paginated, 25 per page)                │
│  Date | Type | Amount | Balance After | Order | Ledger | ↗  │
└─────────────────────────────────────────────────────────────┘
```

### Components

| Element | Component | Notes |
|---|---|---|
| Balance | Large `CardContent` typography | Prominent, formatted |
| Balance chart | `Recharts AreaChart` | 30-day sparkline |
| Transactions table | Shadcn `Table` + `Pagination` | 25 rows per page |
| Type column | `Badge` | deposit = green, withdraw = red |
| Ledger link | `Button` variant ghost with `ExternalLink` icon | Opens Journal Entry detail in a `Sheet` |
| Order link | `Button` variant ghost | Links to order detail in the orders section |
| Fund Wallet button | `Dialog` trigger | Opens Deposit modal (Page 4) |
| Meta details | `HoverCard` on info icon | Shows full meta JSON on hover |

### Behaviour
- Filter by: date range, type (deposit/withdraw), tx_type from meta (issuance/refund/settlement)
- Search by order reference or journal entry reference
- Clicking the ledger icon opens a `Sheet` (right side panel) showing the full journal entry without leaving the page

### Test Checklist
- [ ] Wallet name, slug, balance, and ledger account all render correctly
- [ ] Balance chart renders 30 data points
- [ ] Transactions table paginates correctly
- [ ] Deposit rows show green badge; withdraw rows show red badge
- [ ] Ledger link icon appears only when `journalEntryReference` is set
- [ ] Clicking ledger link opens a Sheet with the journal entry details
- [ ] Order link navigates correctly when `orderReference` is set
- [ ] Date range filter refreshes transactions without full page reload
- [ ] Meta HoverCard shows correctly formatted JSON
- [ ] Fund Wallet button is hidden for users without `accounting.manage`

---

## Page 4 — Fund a Wallet (Deposit Modal)

**Route:** `POST /accounting/wallets/{wallet}/deposit` (modal, not a separate page)  
**File:** Inside `Wallets/Show.tsx` as a `Dialog`  
**Role access:** `accounting.manage` only  

### Purpose
Allow the finance manager to top up a provider wallet or the operating wallet. Posts to the wallet and auto-creates a ledger entry (debit 1110/1210/etc., credit 3100 capital or a funding source account).

### Form Fields

| Field | Type | Validation |
|---|---|---|
| Amount | Number input | > 0, max 2 decimal places for display (3 stored) |
| Currency | Select (pre-filled from wallet) | Read-only if wallet is single-currency |
| Reference | Text input | Optional — bank transfer ref, receipt number |
| Notes | Textarea | Optional — internal notes |
| Funding source | Select | Operating Wallet / External Bank / Capital Injection |

### Components
- Shadcn `Dialog` → `DialogContent` with `DialogHeader`
- `Form` (React Hook Form + Zod validation)
- `Input`, `Select`, `Textarea` from Shadcn
- Submit button with loading spinner state
- Success `Toast` on completion; error `Alert` inline on failure

### Test Checklist
- [ ] Dialog opens and closes correctly
- [ ] Amount field rejects zero and negative values
- [ ] Form cannot be submitted without Amount
- [ ] Successful deposit closes the dialog and refreshes the wallet balance
- [ ] Success toast appears after deposit
- [ ] Ledger entry is created (verify via journal entries page)
- [ ] Button is not rendered for users without `accounting.manage`

---

## Page 5 — Provider Wallets Overview

**Route:** `GET /accounting/providers`  
**File:** `Pages/Accounting/Providers/Index.tsx`  
**Role access:** All three roles  

### Purpose
Consolidated view of all provider wallet balances across all product types, with health indicators and quick links to fund each one.

### Layout
Grid of provider wallet cards (2 columns desktop, 1 mobile):

```
┌────────────────────┐  ┌────────────────────┐
│ ✈ Airline          │  │ 🏨 Hotel            │
│ OYA                │  │ Booking.com API     │
│ LYD 9,050.000      │  │ LYD 4,600.000      │
│ ████████░░ 90%     │  │ ████░░░░░░ 46%     │
│ 12 txns today      │  │ 3 txns today        │
│ [View] [Fund]      │  │ [View] [Fund]       │
└────────────────────┘  └────────────────────┘
```

### Components

| Element | Component | Notes |
|---|---|---|
| Provider card | `Card` | Icon by product type |
| Balance progress bar | Shadcn `Progress` | % of initial deposit remaining; red < 20% |
| Low balance warning | `Badge` destructive + `Tooltip` | Threshold configurable in Settings |
| Fund button | Opens same Deposit `Dialog` as Page 4 | Role-gated |
| Transaction count | Small muted text | "N transactions today" |

### Test Checklist
- [ ] One card renders per configured provider wallet
- [ ] Progress bar reflects correct percentage of balance used
- [ ] Cards with balance below threshold show red low-balance badge
- [ ] Clicking "View" navigates to the provider's wallet detail page
- [ ] Fund button opens deposit dialog
- [ ] Empty state renders with a "No providers configured" message

---

## Page 6 — Journal Entries

**Route:** `GET /accounting/ledger/journal`  
**File:** `Pages/Accounting/Ledger/JournalEntries.tsx`  
**Role access:** All three roles  

### Purpose
Complete chronological log of all double-entry journal entries. The accountant's primary audit tool. Every entry shows its debit/credit lines and links back to the originating order or wallet transaction.

### Data

```ts
interface JournalEntriesProps {
  entries: Paginated<{
    id: string;
    date: string;
    description: string;
    journal: 'AIR' | 'HTL' | 'INS' | 'ESM' | 'STL' | 'GEN';
    reference: string;
    totalDebit: number;
    totalCredit: number;
    isBalanced: boolean; // always true in a healthy system
    lines: {
      accountCode: string;
      accountName: string;
      debit: number | null;
      credit: number | null;
    }[];
    orderReference?: string;
    walletTxReference?: string;
  }>;
  filters: { journal?: string; dateFrom?: string; dateTo?: string; search?: string };
  journalOptions: { value: string; label: string }[];
}
```

### Layout

```
┌─────────────────────────────────────────────────────────────┐
│  Page Header  [Date range ▾] [Journal ▾] [Search...]        │
├────────────────────────────────────────────────────────────-┤
│  Date | Description | Journal | Ref | Debit | Credit | ↕   │
│  ─── (expandable row — shows debit/credit lines) ───        │
│  ...                                                         │
├─────────────────────────────────────────────────────────────┤
│  Pagination                        [Export CSV]             │
└─────────────────────────────────────────────────────────────┘
```

### Components

| Element | Component | Notes |
|---|---|---|
| Filters bar | `DatePickerWithRange` + `Select` + `Input` | All filter via Inertia query params |
| Entries table | Shadcn `Table` | Rows are expandable (collapsible) |
| Expanded row | Sub-table showing account lines | Indented, zebra-striped |
| Journal badge | `Badge` with colour per journal code | AIR=blue, HTL=green, INS=orange, ESM=purple, STL=gray |
| Balanced indicator | Green checkmark / red X | Should always be green; red means data issue |
| Order link | Ghost `Button` with icon | Links to order detail |
| Wallet TX link | Ghost `Button` with icon | Links to wallet transaction |
| Export | `Button` variant outline | Downloads filtered result as CSV |

### Behaviour
- Each row expands in-place to reveal the debit/credit lines
- Only one row can be expanded at a time (accordion behaviour)
- Unbalanced entries (should never appear in production) are highlighted in red and surface an alert

### Test Checklist
- [ ] All journal entries render with correct date, description, and journal code
- [ ] Journal badges show correct colour per code
- [ ] Expanding a row reveals the correct account lines
- [ ] Debit and credit totals are equal on every entry
- [ ] An unbalanced entry (test data) renders with a red highlight
- [ ] Date range filter correctly narrows the result
- [ ] Journal type filter works correctly
- [ ] Search by reference or description works
- [ ] Export CSV downloads a file with the filtered entries
- [ ] Pagination works correctly

---

## Page 7 — Trial Balance

**Route:** `GET /accounting/ledger/trial-balance`  
**File:** `Pages/Accounting/Ledger/TrialBalance.tsx`  
**Role access:** Agency owner · Accountant  

### Purpose
Formal trial balance report showing all accounts with their net debit/credit balance for a selected period. Used for monthly close. Must show totals row that confirms debits = credits.

### Data

```ts
interface TrialBalanceProps {
  period: { from: string; to: string };
  rows: {
    code: string;
    name: string;
    type: 'asset' | 'liability' | 'equity' | 'revenue' | 'expense';
    debit: number;
    credit: number;
    netBalance: number;
  }[];
  totals: { debit: number; credit: number };
  isBalanced: boolean;
}
```

### Layout

```
┌─────────────────────────────────────────────────────────────┐
│  Trial Balance  [Period: Jan 2026 ▾]   [Print] [Export PDF] │
├─────────────────────────────────────────────────────────────┤
│  Account Code | Account Name | Type | Debit | Credit        │
│  ─── grouped by type (Assets / Liabilities / etc.) ───      │
│  ...                                                         │
├─────────────────────────────────────────────────────────────┤
│  TOTALS                           | XXX   | XXX             │
│  ✓ Balanced  / ✗ Imbalance found                            │
└─────────────────────────────────────────────────────────────┘
```

### Components

| Element | Component | Notes |
|---|---|---|
| Period selector | `Select` (monthly presets) + custom `DatePickerWithRange` | Default = current month |
| Table | Shadcn `Table` | Grouped rows with section headers per account type |
| Section headers | Muted `TableRow` with colspan | "Assets", "Liabilities", etc. |
| Totals row | Bold `TableRow` with top border | Sticky at bottom |
| Balance status | `Badge` success="Balanced" / destructive="Imbalance" | Prominent, top right |
| Print | `Button` — calls `window.print()` | Print-friendly CSS class on table |
| Export PDF | `Button` — Inertia request to PDF route | Server-rendered via `dompdf` |

### Test Checklist
- [ ] All accounts from the CoA appear in the table
- [ ] Rows are grouped by account type
- [ ] Totals row shows correct sum of all debits and all credits
- [ ] `isBalanced = true` shows green badge
- [ ] `isBalanced = false` shows red badge and an inline Alert
- [ ] Period selector changes the displayed data
- [ ] Zero-balance accounts are either shown (with 0.000) or hidden — configurable toggle
- [ ] Print view renders cleanly without sidebar
- [ ] Export PDF downloads a correctly formatted file

---

## Page 8 — Chart of Accounts

**Route:** `GET /accounting/ledger/chart-of-accounts`  
**File:** `Pages/Accounting/Ledger/ChartOfAccounts.tsx`  
**Role access:** Accountant · Agency owner  

### Purpose
Read-only view of the tenant's chart of accounts with hierarchy. Clicking any account navigates to its activity detail.

### Layout
Tree structure grouped by account type, collapsible by parent.

| Column | Notes |
|---|---|
| Code | e.g. `1210` |
| Name | e.g. `Airline Provider Wallet` |
| Type | Asset / Liability / etc. as `Badge` |
| Current Balance | Net balance from ledger |
| View Activity | Link to `AccountDetail` page |

### Test Checklist
- [ ] All accounts from the tenant's CoA are listed
- [ ] Hierarchy is correct (parent → children indented)
- [ ] Clicking account name navigates to Account Detail
- [ ] Balance column reflects current ledger balance
- [ ] Type badges are correctly coloured

---

## Page 9 — Account Detail

**Route:** `GET /accounting/ledger/accounts/{code}`  
**File:** `Pages/Accounting/Ledger/AccountDetail.tsx`  
**Role access:** All three roles  

### Purpose
Drill-down view for a single account (e.g. `4100 Airline Ticket Sales`). Shows all journal entry lines that posted to this account with a running balance.

### Data
```ts
interface AccountDetailProps {
  account: { code: string; name: string; type: string };
  period: { from: string; to: string };
  openingBalance: number;
  lines: Paginated<{
    date: string;
    entryDescription: string;
    journal: string;
    debit: number | null;
    credit: number | null;
    runningBalance: number;
    entryReference: string;
  }>;
  closingBalance: number;
}
```

### Layout
Classic accountant T-account / statement of account format:
- Opening balance row at top
- Each line with date, description, debit, credit, running balance
- Closing balance row at bottom
- Line items link to their parent journal entry

### Test Checklist
- [ ] Opening balance matches previous period's closing balance
- [ ] Running balance is calculated correctly on each row
- [ ] Closing balance equals opening + sum of debits - sum of credits (for asset accounts)
- [ ] Clicking a line's reference opens the journal entry detail Sheet
- [ ] Period filter works correctly

---

## Page 10 — Issuance History

**Route:** `GET /accounting/issuances`  
**File:** `Pages/Accounting/Issuance/History.tsx`  
**Role access:** All three roles  

### Purpose
Complete log of all product issuances (airline, hotel, insurance, eSIM) with their financial impact — selling price, provider cost, margin, and links to the ledger entry and order.

### Data

```ts
interface IssuanceHistoryProps {
  issuances: Paginated<{
    id: string;
    orderId: string;
    productType: 'airline' | 'hotel' | 'insurance' | 'esim';
    providerReference: string;
    sellingPrice: number;
    vatAmount: number;
    providerCost: number;
    grossMargin: number;
    grossMarginPct: number;
    issuedAt: string;
    status: 'active' | 'cancelled' | 'voided';
    journalReference: string;
    issuedBy: string; // agent name
    merchantId?: string; // if merchant issuance
  }>;
  summary: {
    totalRevenue: number;
    totalCost: number;
    totalMargin: number;
    countByProduct: Record<string, number>;
  };
}
```

### Layout
- Summary row of 4 KPI cards above the table (total revenue, total cost, total margin, count)
- Filterable by: date range, product type, status, issued by
- Table with all columns; margin column colour-coded (green if > threshold, amber if low)

### Test Checklist
- [ ] Summary KPI cards show correct aggregated figures for the selected period
- [ ] All issuances appear with correct product type badge
- [ ] Cancelled issuances show a strikethrough or `Badge` "Cancelled"
- [ ] Gross margin column is colour-coded correctly
- [ ] Journal entry link opens the entry in a Sheet
- [ ] Order link navigates to the order detail page
- [ ] Filters work: product type, status, date range all narrow results correctly

---

## Page 11 — Settlement Overview

**Route:** `GET /accounting/settlement`  
**File:** `Pages/Accounting/Settlement/Index.tsx`  
**Role access:** Agency owner · Accountant  

### Purpose
For network agencies: shows all outstanding merchant receivables and completed settlement batches. Allows the finance manager to initiate a settlement run.

### Data

```ts
interface SettlementIndexProps {
  agencyType: 'network' | 'merchant' | 'direct';
  // For network agency view:
  outstanding: {
    merchantId: string;
    merchantName: string;
    tenantId: string;
    amount: number;
    oldestUnpaidDate: string;
    orderCount: number;
  }[];
  recentBatches: {
    id: string;
    reference: string;
    merchantName: string;
    amount: number;
    status: 'pending' | 'completed' | 'failed';
    createdAt: string;
  }[];
  totalOutstanding: number;
  // For merchant agency view:
  payableTo: {
    agencyName: string;
    amount: number;
    oldestUnpaidDate: string;
  }[];
  totalPayable: number;
}
```

### Layout

```
┌─────────────────────────────────────────────────────────────┐
│  [Total Outstanding: LYD XX,XXX]        [Run Settlement ▾]  │
├─────────────────────────────────────────────────────────────┤
│  Outstanding Receivables Table                              │
│  Merchant | Amount | Oldest Unpaid | Orders | [Settle Now]  │
├─────────────────────────────────────────────────────────────┤
│  Recent Settlement Batches                                   │
│  Reference | Merchant | Amount | Status | Date | [View]     │
└─────────────────────────────────────────────────────────────┘
```

### Components
- "Run Settlement" is a `Button` with `DropdownMenu` (settle all / settle selected merchants)
- Settlement confirmation uses `AlertDialog` before posting
- Status badges: pending=amber, completed=green, failed=red
- Merchant agency view shows a payable table instead of receivable table

### Test Checklist
- [ ] Network agency sees receivables table; merchant agency sees payables table
- [ ] Direct agency sees an "N/A" state with explanation
- [ ] Total outstanding figure is correct sum of all outstanding rows
- [ ] "Run Settlement" button triggers `AlertDialog` confirmation
- [ ] After confirming, a settlement batch is created and appears in Recent Batches
- [ ] Completed batches show green status badge
- [ ] Clicking "View" on a batch navigates to `BatchDetail` page

---

## Page 12 — Merchant Settlement Aging

**Route:** `GET /accounting/settlement/aging`  
**File:** `Pages/Accounting/Settlement/MerchantAging.tsx`  
**Role access:** Agency owner · Accountant  

### Purpose
Aging report showing how long merchant receivables have been outstanding, bucketed by age. Essential for credit risk management.

### Layout

```
┌────────────┬─────────────┬─────────────┬─────────────┬────────────┐
│ Merchant   │ Current     │ 31–60 days  │ 61–90 days  │ 90+ days   │
│            │ (0–30 days) │             │             │            │
├────────────┼─────────────┼─────────────┼─────────────┼────────────┤
│ Merchant A │ LYD 2,000   │ LYD 0       │ LYD 500     │ LYD 0      │
│ Merchant B │ LYD 1,500   │ LYD 800     │ LYD 0       │ LYD 200    │
├────────────┼─────────────┼─────────────┼─────────────┼────────────┤
│ TOTALS     │ LYD 3,500   │ LYD 800     │ LYD 500     │ LYD 200    │
└────────────┴─────────────┴─────────────┴─────────────┴────────────┘
```

### Components
- Columns 61–90 and 90+ days use amber/red `bg` tint when value > 0
- Totals row is sticky/bold
- Export CSV button

### Test Checklist
- [ ] Each merchant has a row with correctly bucketed amounts
- [ ] Amounts in 61–90 and 90+ columns are highlighted when non-zero
- [ ] Totals row sums each column correctly
- [ ] Export CSV downloads correctly

---

## Page 13 — Cancellations & Voids Audit Log

**Route:** `GET /accounting/cancellations`  
**File:** `Pages/Accounting/Cancellations/Index.tsx`  
**Role access:** All three roles  

### Purpose
Audit trail of all cancellations and voids with the original sale figures, cancellation fee retained, net refunded, and a link to the reversal journal entry.

### Data

```ts
interface CancellationsProps {
  cancellations: Paginated<{
    orderId: string;
    productType: string;
    providerReference: string;
    originalSalePrice: number;
    cancellationFee: number;
    netRefunded: number;
    providerBalanceRestored: boolean;
    cancelledAt: string;
    cancelledBy: string;
    reversalJournalReference: string;
  }>;
}
```

### Layout
- `Table` with all columns
- `Badge` on `providerBalanceRestored`: "Provider Restored" (green) / "Provider Not Restored" (amber)
- Cancellation fee shown in amber if > 0
- Link to reversal journal entry via Sheet

### Test Checklist
- [ ] All cancelled orders appear in the log
- [ ] Net refunded = original price - cancellation fee
- [ ] Provider restored badge reflects the correct value
- [ ] Reversal journal entry link opens the correct entry

---

## Page 14 — Reports Hub

**Route:** `GET /accounting/reports`  
**File:** `Pages/Accounting/Reports/Index.tsx`  
**Role access:** All three roles  

### Purpose
Navigation hub for all reports. Cards linking to each report with a brief description and last-generated date.

### Layout
Grid of report cards (3 columns desktop):

| Card | Description |
|---|---|
| Revenue by Product | Gross sales breakdown by airline, hotel, insurance, eSIM |
| Gross Margin | Revenue vs cost analysis with margin % per product |
| VAT Summary | Tax collected, period totals, filing-ready format |
| Wallet vs Ledger Reconciliation | Detect any mismatches between wallet balance and ledger |
| Trial Balance | All accounts debit/credit summary for monthly close |
| Merchant Settlement Aging | Aged receivables from network merchants |

---

## Page 15 — Revenue Report

**Route:** `GET /accounting/reports/revenue`  
**File:** `Pages/Accounting/Reports/Revenue.tsx`  
**Role access:** All three roles  

### Data
```ts
interface RevenueReportProps {
  period: { from: string; to: string };
  byProduct: { product: string; revenue: number; vatCollected: number; revenueNet: number; orderCount: number }[];
  totalRevenue: number;
  totalVat: number;
  totalNet: number;
  trend: { month: string; airline: number; hotel: number; insurance: number; esim: number }[];
}
```

### Layout
- Period selector at top
- Summary KPI cards: Total Gross · Total VAT · Total Net
- `Recharts BarChart` — monthly trend by product type (stacked bars)
- `Table` — per-product breakdown with order count
- Export CSV / PDF buttons

### Test Checklist
- [ ] KPI cards show correct totals
- [ ] Bar chart renders with correct data per month and product type
- [ ] Table rows sum to the KPI totals
- [ ] Period selector updates all data
- [ ] Export works

---

## Page 16 — Gross Margin Report

**Route:** `GET /accounting/reports/gross-margin`  
**File:** `Pages/Accounting/Reports/GrossMargin.tsx`  

### Data
```ts
interface GrossMarginProps {
  period: { from: string; to: string };
  rows: { product: string; revenue: number; cost: number; margin: number; marginPct: number }[];
  totals: { revenue: number; cost: number; margin: number; marginPct: number };
  trend: { month: string; marginPct: number }[];
}
```

### Layout
- Summary: Total Revenue · Total Cost · Gross Margin · Margin %
- `Recharts LineChart` — margin % trend over time
- Table: per-product margin with colour-coded % (green > 15%, amber 5–15%, red < 5%)

### Test Checklist
- [ ] Margin % = (revenue - cost) / revenue × 100
- [ ] Colour coding is correct per threshold
- [ ] Totals row is correct
- [ ] Trend line chart renders

---

## Page 17 — VAT Summary Report

**Route:** `GET /accounting/reports/vat`  
**File:** `Pages/Accounting/Reports/VATSummary.tsx`  

### Data
```ts
interface VATSummaryProps {
  period: { from: string; to: string };
  rows: { date: string; orderId: string; productType: string; grossAmount: number; vatAmount: number; vatRate: number }[];
  totalVatCollected: number;
  totalGross: number;
}
```

### Layout
- Period selector
- Summary: Total Gross Sales · Total VAT Collected
- Table: per-transaction VAT breakdown
- "Filing Ready" export (formatted for accountant submission)

### Test Checklist
- [ ] VAT amount = gross × rate / (1 + rate) for tax-inclusive pricing
- [ ] Total VAT collected matches account 2400 balance in ledger
- [ ] Filing export downloads correctly

---

## Page 18 — Wallet vs Ledger Reconciliation

**Route:** `GET /accounting/reports/reconciliation`  
**File:** `Pages/Accounting/Reports/Reconciliation.tsx`  

### Purpose
The most important control report. Compares every wallet's actual balance against its corresponding ledger account balance. Any mismatch is a data integrity issue that must be investigated.

### Data
```ts
interface ReconciliationProps {
  lastRunAt: string;
  results: {
    walletName: string;
    walletSlug: string;
    ledgerAccount: string;
    walletBalance: number;
    ledgerBalance: number;
    difference: number;
    status: 'matched' | 'mismatch';
  }[];
  overallStatus: 'all_matched' | 'has_mismatches';
}
```

### Layout

```
┌─────────────────────────────────────────────────────────────┐
│  Reconciliation Report   Last run: [date]   [Re-run Now]    │
│  Overall status: ✓ All Matched / ✗ X Mismatches Found       │
├─────────────────────────────────────────────────────────────┤
│  Wallet | Ledger A/C | Wallet Balance | Ledger Balance | Δ  │
│  ✓ Operating Wallet (1110)  LYD 8,000  LYD 8,000  LYD 0   │
│  ✗ Airline Wallet (1210)    LYD 4,050  LYD 4,100  LYD 50  │
└─────────────────────────────────────────────────────────────┘
```

### Components
- Mismatch rows get a red `bg` tint and an "Investigate" `Button` that links to both the wallet and account detail pages
- "Re-run Now" dispatches a background job; page shows a loading state until complete
- Export CSV

### Test Checklist
- [ ] All wallet/ledger pairs are listed
- [ ] Matched rows show green checkmark; mismatched rows show red X
- [ ] Difference column shows absolute difference
- [ ] Mismatch rows render in red tint
- [ ] "Investigate" button links to both the wallet and account detail pages
- [ ] "Re-run Now" triggers reconciliation and refreshes results
- [ ] Overall status banner is correct

---

## Page 19 — Accounting Settings

**Route:** `GET /accounting/settings`  
**File:** `Pages/Accounting/Settings/Index.tsx`  
**Role access:** `accounting.manage` only  

### Settings Groups

**General**
- Default currency (pre-filled, read-only if single-currency)
- Fiscal year start month (Select: January–December)
- VAT rate % (Number input)
- VAT registration number (Text input)

**Wallet Thresholds**
- Per-provider wallet low-balance threshold (number input per provider)
- Alert email recipients for low-balance notifications

**Revenue Recognition**
- Recognition trigger (read-only: "Provider Confirmation" — fixed per accounting plan)
- Gross/net display (read-only: "Gross" — fixed per accounting plan)

**Monthly Close**
- Auto-lock journal entries after close (Toggle)
- Close date for current period (DatePicker — triggers lock)

**Reconciliation**
- Auto-reconcile schedule (Daily / Weekly / Manual — Select)
- Alert on mismatch (Toggle)

### Components
- Shadcn `Tabs` for each settings group
- Each group uses `Form` with React Hook Form + Zod
- `Switch` for toggle settings
- `Input`, `Select`, `DatePicker`
- Save button with success `Toast`

### Test Checklist
- [ ] All settings groups render correctly
- [ ] Read-only fields cannot be edited
- [ ] VAT rate validates as a number between 0 and 100
- [ ] Fiscal year start month saves correctly
- [ ] Low-balance threshold saves per provider
- [ ] Toggle settings save and reflect immediately
- [ ] Close date field triggers a confirmation `AlertDialog` before saving
- [ ] Settings page is not accessible to users without `accounting.manage`

---

## Global Components (Reusable Across All Pages)

Build these once and import across all accounting pages.

### `AccountingKpiCard`
```tsx
interface Props {
  title: string;
  value: string | number;
  currency?: string;
  trend?: { direction: 'up' | 'down' | 'flat'; pct: number };
  icon?: ReactNode;
  linkTo?: string;
  variant?: 'default' | 'warning' | 'danger';
}
```

### `JournalEntrySheet`
A `Sheet` (right side panel) that loads and displays a full journal entry given a reference string. Used across Wallets, Issuance History, Cancellations, and Account Detail pages without navigation.

```tsx
interface Props {
  reference: string;
  open: boolean;
  onClose: () => void;
}
```

### `AmountDisplay`
Formats a number as a currency string with correct decimal places and colour.
```tsx
interface Props {
  amount: number;
  currency?: string;
  decimals?: number; // default 3 for LYD
  colorize?: boolean; // positive=green, negative=red
}
```

### `PeriodSelector`
Reusable date range picker that outputs `{ from, to }` and updates Inertia query params.

### `ExportButton`
Handles CSV and PDF export with loading state and error handling.

---

## Role-Based Access Summary

| Page | Agency Owner | Accountant | Merchant Manager |
|---|---|---|---|
| Dashboard | ✓ | ✓ | ✓ |
| All Wallets | ✓ | ✓ | own wallet only |
| Wallet Detail | ✓ | ✓ | own wallet only |
| Fund Wallet | ✓ | ✓ | ✗ |
| Provider Wallets | ✓ | ✓ | ✗ |
| Journal Entries | ✓ | ✓ | ✗ |
| Trial Balance | ✓ | ✓ | ✗ |
| Chart of Accounts | ✓ | ✓ | ✗ |
| Account Detail | ✓ | ✓ | ✗ |
| Issuance History | ✓ | ✓ | own issuances only |
| Settlement | ✓ | ✓ | own payables only |
| Settlement Aging | ✓ | ✓ | ✗ |
| Cancellations | ✓ | ✓ | own only |
| Reports (all) | ✓ | ✓ | ✗ |
| Settings | ✓ | ✗ | ✗ |

---

## Build Order for AI Agent

Follow this order strictly — each step depends on the previous.

| Step | What to Build | Depends On |
|---|---|---|
| 1 | `AccountingLayout` + sidebar nav + shared components | — |
| 2 | Dashboard (Page 1) | Step 1 |
| 3 | All Wallets + Wallet Detail + Deposit modal (Pages 2–4) | Step 1 |
| 4 | Provider Wallets (Page 5) | Step 3 |
| 5 | Journal Entries (Page 6) + `JournalEntrySheet` component | Step 1 |
| 6 | Trial Balance (Page 7) | Step 5 |
| 7 | Chart of Accounts + Account Detail (Pages 8–9) | Step 5 |
| 8 | Issuance History (Page 10) | Steps 3 + 5 |
| 9 | Settlement (Pages 11–12) | Steps 3 + 5 |
| 10 | Cancellations (Page 13) | Steps 5 + 8 |
| 11 | Reports Hub + all report pages (Pages 14–18) | Steps 2–10 |
| 12 | Accounting Settings (Page 19) | Step 1 |