# Accounting Knowledge Transfer — CoA, Journal Entries, General Ledger, Balance Sheet

**Source project:** TAMS / Booknow (Laravel 12, multi-tenant Stancl, Inertia React, Abivia Ledger)  
**Scope:** Chart of Accounts, Journal Entries, General Ledger, Balance Sheet only  
**Out of scope:** Trial Balance, Income Statement, VAT/Revenue/Gross Margin reports, Inventory UI, Wallets UI, Settlement aging, Account Routing UI (routing is mentioned only where it feeds automated journal posts)

This document is written so another team can reimplement the same four areas in a different codebase.

---

## 1. Architecture overview

### 1.1 Stack layers

| Layer | Responsibility |
|--------|----------------|
| **Abivia Ledger** (`abivia/ledger` ^1.11) | Source of truth for accounts, journal headers/lines, currencies, sub-journals. Enforces double-entry via `JournalEntryController` / `LedgerAccountController`. |
| **TAMS services** | Bootstrap CoA from JSON templates, CoA metadata (`coa_settings`), numbering, lifecycle (delete/purge), GL/BS query logic, automated posting helpers. |
| **TAMS HTTP + Inertia** | Admin UI under tenant `accounting/*` routes (role: `admin`). |
| **bavix wallets** | Operational money movement; ledger mirrors financial events. Wallets are *not* the GL — they post *into* the GL. |

TAMS mostly **does not** call Abivia’s HTTP JSON API. It instantiates Abivia controllers in-process:

- `LedgerAccountController::create` / `::add`
- `JournalEntryController::add` / `::update` / `::delete`
- `SubJournalController::add`

### 1.2 Critical Abivia conventions

1. **Signed amounts:** `journal_details.amount` is credit-positive / debit-negative (BCD stored as decimal string). Debit 100 → `-100`; credit 100 → `+100`.
2. **Unique names:** Display names are unique per language across accounts (`ledger_names`). Same English name on two codes → `Breaker` RULE_VIOLATION.
3. **Carbon mutable:** Abivia’s `Revision::create()` rejects `CarbonImmutable`. Wrap every Abivia write with:

```php
$original = get_class(Date::now());
Date::use(Carbon::class);
try { /* Abivia call */ } finally { Date::use($original); }
```

4. **Static root cache:** `LedgerAccount::resetRules()` must run when switching tenant context so multi-tenant jobs do not leak root state.

### 1.3 Multi-tenancy

- Each tenant has its **own database**. Ledger tables have **no `tenant_id`**.
- Migrations: `database/migrations/tenant/`.
- On `TenantCreated`: migrate DB → `BootstrapTenantLedgerJob` → template CoA + journals + `coa_settings` sync (+ wallet provisioning unrelated to this doc).
- Always call work inside `$tenant->run(fn () => …)` or tenant HTTP middleware.

### 1.4 Key file map

```
resources/ledger/templates/{direct,network,merchant}-agency.json
app/Services/Accounting/
  LedgerBootstrapService.php
  CoaSettingsSyncService.php
  CoaAccountLifecycleService.php
  AccountNumberingService.php
  LedgerPostingService.php          # automated JE posts
  LedgerQueryService.php            # CoA list balances
  JournalEntryAttachmentService.php
  Reports/
    GeneralLedgerService.php
    BalanceSheetService.php
app/Http/Controllers/Tenant/Accounting/
  LedgerController.php              # CoA + JE + account detail
  AccountingReportController.php    # GL + BS endpoints
app/Models/Tenant/
  ChartOfAccount.php                # extends Abivia LedgerAccount + SoftDeletes
  CoaSetting.php
app/Actions/Finance/
  InitializeTenantLedger.php        # LEGACY smaller CoA — prefer template bootstrap
  PostToLedger.php                  # order → JE
app/Jobs/BootstrapTenantLedgerJob.php
resources/js/Pages/Accounting/Ledger/
  ChartOfAccounts.jsx
  JournalEntries.jsx
  AccountDetail.jsx
resources/js/Pages/Accounting/Reports/
  GeneralLedger.jsx
  BalanceSheet.jsx
resources/js/Components/Accounting/
  JournalEntryFormDialog.jsx
  JournalEntrySheet.jsx
  AccountCombobox.jsx
```

---

## 2. Chart of Accounts (CoA)

### 2.1 Dual model: ledger + settings

| Store | Table | Role |
|--------|--------|------|
| **Abivia** | `ledger_accounts` | Posting identity: `ledgerUuid` (PK), `code`, `parentUuid`, `debit`/`credit`, `category`, `closed`, soft-delete `deleted_at` |
| **Abivia** | `ledger_names` | Localized display names (`ownerUuid`, `language`, `name`) — unique per language |
| **TAMS** | `coa_settings` | UI/metadata mirror: `display_name`, `account_type`, `parent_code`, `is_system`, `is_active`, `description`, `sort_order` |

`ChartOfAccount` extends `Abivia\Ledger\Models\LedgerAccount` and adds SoftDeletes on `ledger_accounts`.

**Rule for reimplementation:** Never post against `coa_settings` alone. Always create the Abivia account first, then mirror settings.

### 2.2 Account type map (first digit)

Used by `CoaSettingsSyncService::TYPE_MAP` and GL typing:

| Prefix | Type | Normal balance |
|--------|------|----------------|
| `1` | asset | debit |
| `2` | liability | credit |
| `3` | equity | credit |
| `4` | revenue | credit |
| `5` | expense (COGS) | debit |
| `6` | purchase | debit |
| `7` | expense (opex) | debit |
| `8` | asset (settlement clearing) | debit |

Manual CoA create uses `DEBIT_TYPES = [asset, expense, purchase]` to set Abivia `debit`/`credit` flags.

### 2.3 Template CoA (canonical structure)

Templates live at `resources/ledger/templates/`. Tenant type selects file:

| `tenant.type` | Template |
|---------------|----------|
| `network` | `network-agency.json` |
| `merchant` | `merchant-agency.json` |
| anything else (`direct`, …) | `direct-agency.json` |

In the current codebase the three templates are **identical**. Currency default: **LYD**, **3 decimal places**.

#### Full account list (codes matter for posting)

**Assets (1xxx)**

| Code | Name | Parent |
|------|------|--------|
| 1000 | Assets | — |
| 1100 | Cash & Bank | 1000 |
| 1110 | Agency Operating Wallet | 1100 |
| 1120 | Merchant Wallet | 1100 |
| 1200 | Provider Prepaid Balances | 1000 |
| 1210–1240 | Airline / Hotel / Insurance / eSIM Provider Wallet | 1200 |
| 1300 | Receivables | 1000 |
| 1310 | Customer Receivable | 1300 |
| 1320 | Merchant Receivable | 1300 |
| 1400 | Inventory | 1000 |
| 1410 | Travel Product Inventory | 1400 |
| 1420 | Physical Goods Inventory | 1400 |

**Liabilities (2xxx)** — 2000…2510/2520 (provider payables, network payable, deposits, VAT/taxes, supplier AP)

**Equity (3xxx)** — 3100 Capital, 3200 Retained Earnings, 3300 Current Year P/L

**Revenue (4xxx)** — 4100–4700 product sales, markup, network commission, cancellation fees

**COGS (5xxx)** — 5100–5500 provider/merchant costs

**Purchases (6xxx)** — 6000 + 6010–6060 (product/inventory/other purchases)

**Operating expenses (7xxx)** — 7000, 7100 Refunds, 7200 Settlement adj, 7300 FX, 7500 Commission expense

**Settlement clearing (8xxx)** — 8000, 8100, 8200, 8400 (debit-normal; treated as assets on BS)

#### Sub-journals (template)

| Code | Name |
|------|------|
| GEN | General |
| AIR | Airline |
| HTL | Hotel |
| INS | Insurance |
| ESM | eSIM |
| STL | Settlement |

### 2.4 Bootstrap flow

**Service:** `LedgerBootstrapService::bootstrapForTenant(Tenant $tenant, string $currency = 'LYD')`

1. Resolve template path; optionally override currencies.
2. **Strip `journals`** from the Create payload (Abivia RootController bug when journals are objects).
3. `LedgerAccount::resetRules()`; force mutable Carbon.
4. **If no root:** `Create::fromArray($template)` → `LedgerAccountController::create`.
5. **If root exists:** `addMissingAccounts()` — idempotent; skips codes that already exist.
6. Create missing sub-journals via `SubJournalController::add`.
7. `CoaSettingsSyncService::syncFromLedger(markSystem: true)`.

**Name conflict handling (important for upgrades):** Before adding a missing template account, `releaseTemplateName()` renames any *other* live account that already holds that display name:

- If conflict’s code is in the template with a different name → restore that template name (typical after renumber).
- Else → rename to `"{Name} ({code})"`.

**Job:** `BootstrapTenantLedgerJob` runs bootstrap inside tenant context and resets Abivia rules before/after.

**Legacy:** `InitializeTenantLedger` builds a smaller hardcoded CoA. Prefer template bootstrap for new systems; keep awareness that some older commands still call the legacy action.

### 2.5 CoA settings sync

`CoaSettingsSyncService::syncFromLedger(bool $markSystem = true)`:

- Reads live (non-deleted) `ledger_accounts` + `ledger_names`.
- Upserts `coa_settings` by `code`.
- Sets `account_type` from first digit; `parent_code` from parent UUID→code map.
- New rows: `is_system = $markSystem`, `is_active = true`.

### 2.6 Account numbering

`AccountNumberingService`:

| Type | Suggested range |
|------|-----------------|
| asset | 1000–1999 |
| liability | 2000–2999 |
| equity | 3000–3999 |
| revenue | 4000–4999 |
| purchase | 6000–6999 |
| expense | **7000–7999** (not 5xxx — 5xxx is COGS in template) |

Child step heuristic:

- Parent like `4000` (thousands) → children step **100** (`4100`, `4200`, …)
- Parent like `4100` (hundreds) → step **10**
- Else step **1**

Collision checks both `coa_settings` and live `ledger_accounts`.  
HTTP: `GET accounting/ledger/chart-of-accounts/next-code`.

### 2.7 CoA CRUD (manual)

Controller: `LedgerController`

| Action | Behavior |
|--------|----------|
| **Create** | Purge soft-deleted/unused blockers → validate unique code on `ledger_accounts` + `coa_settings` → Abivia `add` with debit/credit from type → `CoaSetting::create(is_system: false)` |
| **Update** | Rename EN `ledger_names` + update `coa_settings` display/description/active. **Code is immutable.** |
| **Delete** | Block if `is_system`, has journal details, or has children → `CoaAccountLifecycleService::hardDeleteAccount` (Abivia `AccountLogic` + force-delete CoA soft row + delete setting) |

**Purge for reuse:** Soft-deleted / inactive non-system unused accounts that block a code or name can be hard-deleted before recreate.

### 2.8 Historical renumber (upgrade path)

Migration: `database/migrations/tenant/2026_07_04_140000_renumber_ledger_expense_and_clearing_accounts.php`

Purpose: free **6xxx** for Purchases.

| Old | New | Meaning |
|-----|-----|---------|
| 7000→8000, 7100→8100, 7200→8200, 7400→8400 | Clearing moves to 8xxx |
| 6000→7000, 6100→7100, 6200→7200, 6300→7300 | OpEx moves to 7xxx |

Then calls `bootstrapForTenant` to insert missing **6xxx** purchases (and any other missing template accounts).

Updates use **string-bound SQL** so MySQL does not coerce codes like `2200_ST` when matching `7000`.

### 2.9 UI & routes (CoA)

Prefix: `accounting` (tenant), middleware admin.

| Method | Path | Name | Page |
|--------|------|------|------|
| GET | `/ledger/chart-of-accounts` | `accounting.ledger.coa` | `ChartOfAccounts.jsx` |
| GET | `/ledger/chart-of-accounts/next-code` | `…coa.next-code` | — |
| POST | `/ledger/chart-of-accounts` | `…coa.store` | — |
| PUT | `/ledger/chart-of-accounts/{code}` | `…coa.update` | — |
| DELETE | `/ledger/chart-of-accounts/{code}` | `…coa.destroy` | — |
| GET | `/ledger/accounts/{code}` | account detail | `AccountDetail.jsx` |

UI patterns: hierarchical list, system lock, type badges, `AccountCombobox`, next-code fetch when parent/type changes.

### 2.10 Account routing (only as CoA destination map)

Automated posts resolve debit/credit codes via `account_routing` + `AccountRoutingService` (defaults in `AccountRoutingDefaults`). Manual journal UI **does not** use routing — the user picks accounts. When porting automated sales/issuance, you need either the same routing table or hardcoded equivalents of these codes (e.g. Dr `1310` / Cr `4xxx` / Cr `4500` / Dr `5xxx` / Cr provider wallet `12x0`).

---

## 3. Journal Entries

### 3.1 Data model

**`journal_entries` (header)** — key fields:

- `journalEntryId`, `transDate`, `subJournalUuid`, `currency`, `description`
- `opening`, `clearing`, `reviewed`, `locked`
- `extra` (JSON string/array) — TAMS conventions below
- `revision` / revision hash — required for Abivia update/delete

**`journal_details` (lines)**

- `journalEntryId`, `ledgerUuid`, `amount` (signed), optional reference UUID

**Attachments:** stored via `JournalEntryAttachmentService`; metadata in `extra.attachment`.

### 3.2 `extra` JSON conventions

| Key | Meaning |
|-----|---------|
| `source: "manual"` | Created in admin UI — **only these may be edited/deleted** |
| `reference_number` | Optional user-facing reference on manual entries |
| `reference` | Business reference on automated posts (`LedgerPostingService`) |
| `journal` | Fallback journal code if sub-journal missing |
| `attachment` | File metadata |

Detection:

```php
($extra['source'] ?? null) === 'manual'
```

System entries (orders, wallets, settlement, cancellations) must **not** be edited/deleted in UI — instruct users to post adjusting/reversal entries.

### 3.3 Manual create / update / delete

**Create** (`storeJournalEntry`):

1. Validate: `transDate`, `description`, optional `referenceNumber`, `journal ∈ GEN|AIR|HTL|INS|ESM|STL`, ≥2 lines, account codes exist, optional attachment (image/PDF, max 5 MB).
2. Server balance: `|Σdebit − Σcredit| ≤ 0.001` (3 dp).
3. Build Abivia `Entry::fromArray` with per-line `debit`/`credit` strings (not signed amounts — Abivia converts).
4. `JournalEntryController::add`.
5. Persist attachment if present.

**Update / delete:** same balance rules; require `isManualEntry` and not `locked`; pass `revision` hash; use `Message::OP_UPDATE` / `OP_DELETE`.

**Client:** `JournalEntryFormDialog.jsx` also checks balance at 3 decimal places before submit.

### 3.4 Automated posting (feeds GL/BS)

**`LedgerPostingService::post($journal, $description, $reference, $details, $clearing = false)`**

- Builds Abivia Entry with `extra.reference`, `transDate = now`, optional clearing flag.
- Higher-level helpers: wallet tx, issuance, merchant/network issuance, reversals, settlement/inventory (inventory out of this doc’s product scope but posts to CoA).

**Journal code resolution:** product type → `AIR` / `HTL` / `INS` / `ESM`; settlement → `STL`; else `GEN`.

**`PostToLedger::execute(Order)`** — per order item with wallet tx and no `ledger_entry_id`: posts sale/COGS/tax/wallet lines via routing; stores `order_items.ledger_entry_id`.

Optional driver: `AbiviaLedgerDriver::postOperationJournal` implements a generic debit/credit array API.

### 3.5 UI & routes (JE)

| Method | Path | Name |
|--------|------|------|
| GET | `/ledger/journal` | `accounting.ledger.journal` |
| POST | `/ledger/journal` | `…journal.store` |
| GET | `/ledger/journal/{id}` | `…journal.show` |
| PUT | `/ledger/journal/{id}` | `…journal.update` |
| DELETE | `/ledger/journal/{id}` | `…journal.destroy` |
| GET | `/ledger/journal/{id}/attachment` | `…journal.attachment` |

Pages: `JournalEntries.jsx` (list + filters `dateFrom`/`dateTo`/`search`), form dialog, read-only sheet.

### 3.6 Reimplementation checklist (JE)

1. Enforce double-entry balance client + server at currency precision (here: 3 dp, LYD).
2. Persist a clear manual vs system marker; protect system rows.
3. Store business references in structured `extra` (or equivalent).
4. Use sub-journals for product/settlement segmentation.
5. Optimistic concurrency via revision token if the ledger engine supports it.
6. Force mutable date library around ledger writes if required by the engine.

---

## 4. General Ledger

### 4.1 Purpose

Account-by-account statement for a **period**: opening balance + period lines + running balance + closing.

**Not** Abivia’s built-in report tables — TAMS computes from `journal_details` + `journal_entries.transDate`.

### 4.2 Algorithm (`GeneralLedgerService::generate`)

Inputs: `$from`, `$to`, optional `$accountCodes`, `$skipInactive = true`.

For each non-category account with a code:

1. **Opening** = `SUM(amount)` for details whose entry `transDate < $from`.
2. **Period lines** = details with entry in `[$from, $to]`, ordered by `journalEntryId`.
3. Map amount: `< 0` → debit absolute; `> 0` → credit.
4. Journal label from `SubJournal` map, else `extra.journal`, else `GEN`.
5. Reference from `extra.reference`.
6. **Running balance** starts at opening; each line adds `(credit − debit)` (credit-positive convention).
7. Skip inactive accounts (no lines and ~zero opening) when `$skipInactive`.
8. Type from first digit (`1|8` asset, `2` liability, `3` equity, `4` revenue, `6` purchase, else expense).

Output shape per account:

```
code, name, type, openingBalance, lines[], closingBalance, totalDebit, totalCredit
```

Each line: `date, description, journal, reference, debit, credit, entryId, runningBalance`.

### 4.3 HTTP & UI

`AccountingReportController::generalLedger`

- Query: `from`, `to` (default current month), optional `account`.
- Inertia page: `Accounting/Reports/GeneralLedger.jsx` — filters, account combobox, per-account cards, print.

**Related single-account view:** `LedgerController::accountDetail` uses the same opening/running math for one code (`from`/`to`) → `AccountDetail.jsx`. Useful drill-down from CoA / JE links.

### 4.4 Reimplementation notes

- Always cast/sum amounts at fixed decimal precision (3).
- Opening is **strictly before** period start; closing = opening ± period activity under the same sign convention.
- Present debits/credits as unsigned columns for humans; keep signed storage internally.

---

## 5. Balance Sheet

### 5.1 Purpose

Point-in-time financial position: **Assets = Liabilities + Equity** as of `asOf` date.

P&L accounts are **not closed** into equity in this system, so the service **calculates current profit** from P&L nets and adds it to equity so the equation balances.

### 5.2 Algorithm (`BalanceSheetService::generate`)

1. For every non-category account: `net = SUM(amount)` where entry `transDate <= asAtDate`.
2. **Assets:** prefixes `1` and `8`, debit-normal → display `−net` (so debit balances show positive).
3. **Liabilities:** prefix `2`, credit-normal → display `net`.
4. **Equity accounts:** prefix `3`, credit-normal → display `net`.
5. **Calculated profit:** sum of raw nets for prefixes `4,5,6,7` (credit-positive: revenue positive, expenses negative → net profit). Add to equity total as `calculatedProfit`.
6. Omit near-zero balances from lists.
7. `isBalanced` if `|assets − (liabilities + equity)| < 0.01`.

Return shape:

```
asAtDate,
assets: { accounts[], total },
liabilities: { accounts[], total },
equity: { accounts[], total, calculatedProfit },
totals: { assets, liabilities_and_equity },
isBalanced
```

### 5.3 HTTP & UI

`AccountingReportController::balanceSheet` — query `asOf` (default today) → `Accounting/Reports/BalanceSheet.jsx`.

UI: as-of picker, Assets vs Liabilities+Equity columns, italic “Current Period Profit/Loss (calculated)”, balanced badge / imbalance alert.

### 5.4 Reimplementation notes

- Treat clearing (`8xxx`) as **assets** if you keep debit-normal clearing accounts.
- If you later implement true year-end close into `3200`/`3300`, remove or reduce the calculated-profit bridge.
- Balance tolerance should match currency precision (here 0.01 absolute for balanced flag; 3 dp elsewhere).

---

## 6. End-to-end data flow

```
TenantCreated
  → tenant DB migrate
  → BootstrapTenantLedgerJob
  → LedgerBootstrapService (template accounts + sub-journals)
  → CoaSettingsSyncService (is_system=true)
  → [optional] account_routing seed for automated posts

Manual JE (admin)
  → LedgerController.storeJournalEntry (extra.source=manual)
  → JournalEntryController.add
  → journal_entries + journal_details

Automated sale / issuance / wallet
  → PostToLedger / LedgerPostingService
  → resolve CoA codes (routing or hardcoded)
  → JournalEntryController.add (no source=manual)
  → link business row (e.g. order_items.ledger_entry_id)

General Ledger
  → SUM details by account + date window (opening before from)

Balance Sheet
  → SUM details by account through asOf
  → classify 1/8, 2, 3 + roll 4–7 into equity profit
```

---

## 7. Database schema (minimum to port)

### 7.1 Required Abivia-style tables

- `ledger_accounts` (+ soft deletes if you support recycle)
- `ledger_names`
- `ledger_currencies`, `ledger_domains` (Abivia bootstrap)
- `sub_journals`
- `journal_entries`
- `journal_details`
- `journal_references` (if using Abivia references)
- `ledger_balances` (Abivia maintains; TAMS GL/BS recompute from details)

### 7.2 TAMS-specific

- `coa_settings` — UI metadata / system flag / active flag
- `account_routing` — only if porting automated posting destinations

Notable migrations:

- `2026_04_24_023710_ledger_create_tables_v2.php`
- `2026_06_11_200930_add_deleted_at_to_ledger_accounts.php`
- `2026_07_04_140000_renumber_ledger_expense_and_clearing_accounts.php`
- `2026_07_04_141000_create_coa_settings_table.php`

---

## 8. Tests that encode intended behavior

| File | What it locks in |
|------|------------------|
| `tests/Accounting/ManualLedgerManagementTest.php` | Manual CoA/JE HTTP; system entry protection; delete guards |
| `tests/Accounting/UpgradeV2/AccountNumberingAndCoaTest.php` | Next codes, duplicates, system delete blocked |
| `tests/Accounting/UpgradeV2/CoaRenumberMigrationTest.php` | Renumber safety + bootstrap name conflict rename |
| `tests/Accounting/UpgradeV2/FinancialReportsTest.php` | GL opening/running; BS balance + calculated profit |
| `tests/Feature/Accounting/Phase2/ChartOfAccountsTest.php` | CoA bootstrap presence |
| `tests/Accounting/Phase4/LedgerBridgeTest.php` | Automated posting into ledger |
| `tests/Unit/PostToLedgerTest.php` | Order → journal posting |

When porting, recreate equivalent tests first around sign convention, balance enforcement, manual vs system JE, GL opening math, and BS calculated profit.

---

## 9. Porting playbook (another project)

1. **Choose ledger engine** (Abivia or equivalent double-entry). Preserve **debit-negative storage** or adapt all presentation math consistently.
2. **Copy account range design** (1–8) and the template JSON as the seed CoA + sub-journals.
3. **Bootstrap on tenant/org create**; sync a settings/metadata table if the UI needs `is_system` / inactive.
4. **Manual JE module** with balance checks, sub-journal, optional reference, attachment, edit/delete only for user-sourced entries.
5. **GL** = period statement from transaction lines (do not rely on cached balances alone).
6. **BS** = cumulative to as-of + P&L bridge into equity until you implement formal close.
7. **Wire automated business events** to the same CoA codes (routing table or constants).
8. **Precision:** decide decimals (TAMS agency books use 3 for LYD) and apply everywhere (validation, sums, display).
9. **Tenant isolation:** separate DB or strict `tenant_id` — never share ledger rows across orgs without scoping.
10. **Upgrade story:** if you ever reshuffle ranges (like 6xxx purchases), plan renumber + name-conflict release before inserting new accounts.

---

## 10. Explicitly excluded from this document

Do not treat these as part of the CoA / JE / GL / BS core when porting unless needed later:

- Trial Balance page/service
- Income Statement
- VAT / Revenue / Gross Margin / Reconciliation report pages
- Inventory module UI (accounts `1410`/`1420`/`6050` exist on CoA for future posts)
- Wallet & provider wallet management UI
- Settlement aging / cancellation audit UIs
- Account Routing settings UI (only the destination codes matter for automated JE)

---

*Generated from the TAMS codebase for cross-project knowledge transfer. Primary sources: ledger templates, `LedgerBootstrapService`, `LedgerController`, `GeneralLedgerService`, `BalanceSheetService`, tenant accounting routes, and UpgradeV2 accounting tests.*
