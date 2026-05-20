# TAMS – Roadmap & Phase Execution Tracker

## Purpose

This document tracks the next execution phases after the core tourism services reached agency-side functionality. It records decisions, requirements, completion status, and gaps so each phase can be implemented and validated without losing context.

## Current Baseline

- Agency-side flight search, pricing, issuance, and cancellation/void/refund flows exist.
- Agency-side compulsory, travel, and orange insurance flows exist, with Orange/Travel UI polish deferred.
- Agency-side hotel search, pricing, checkRate, booking, provider wallet validation, markup pricing, order display, CreditCheck, and direct/pending cancellation handling exist.
- Tenant provider wallets use `bavix/laravel-wallet`.
- Existing tenant migrations must be kept current with `php artisan tenants:migrate`.

## Phase Summary

| Phase | Name | Status | Primary Goal |
|---|---|---:|---|
| 1 | Merchant Join Workflow | Completed | Allow merchants to join agency networks through invitations and choose enabled provider APIs. |
| 2 | Merchant Product Tests | Completed | Validate flight, hotel, and insurance flows from merchant side with dual-wallet rules. |
| 3 | Order Page Redesign + Accounting UI | Completed | Make order/item information clearer and easier to operate for all product types. Build the full accounting section UI (19 pages). |
| 4 | Notifications & WhatsApp | Completed | Add email and WhatsApp notifications for booking, issuance, cancellation, and files. |
| 5 | PDF / Printable Documents | Completed | Add printable ticket, hotel voucher, insurance policy, item PDF, and full order PDF. |
| 6 | Tenant External APIs | Completed | Expose secure tenant APIs for agency integrations and mobile apps. |

---

## Phase 1 — Merchant Join Workflow

### Confirmed Requirements

- Agencies must have a unique **agency tenant number** used for merchant invitations.
- The tenant number format is `AG-100001`.
- Agency invites a merchant using the agency tenant number and merchant contact/email.
- Agency chooses which tenant provider APIs are offered to the merchant.
- Agency defines merchant shared-profit terms per offered API. Merchant rates must be equal to or lower than the agency provider discount/markup.
- Merchant receives join email and notification.
- Merchant opens join page and sees only provider APIs offered by the agency.
- Merchant sees the offered discount/markup terms before accepting provider APIs.
- Merchant can enable only the provider APIs they want; accepting all offered APIs is not required.
- Join becomes confirmed after merchant selection.
- Agency receives join confirmation email/notification.

### Mandatory Rules

- Do not add a `tenant_type` column.
- Store network memberships and provider allocations in the central database.
- Do not copy agency provider credentials into merchant tenant databases.
- Merchant can join multiple agency networks.
- Provider allocations must carry source metadata into later product offers and orders.

### Proposed Data Model

- `tenants.agency_number` or equivalent unique central tenant identifier.
- `network_memberships`
  - `agency_tenant_id`
  - `merchant_tenant_id` nullable until accepted if invitation starts by email only
  - `merchant_email`
  - `invitation_token`
  - `status`: `pending`, `active`, `suspended`, `revoked`
  - `accepted_at`, `expires_at`, `created_by`
- `provider_allocations`
  - `network_membership_id`
  - `provider_type`: `airline`, `insurance`, `hotel`, `sim`
  - `provider_id`
  - provider/source metadata
  - `is_offered_by_agency`
  - `is_enabled_by_merchant`
  - `enabled_at`
  - `commission_rate` / `markup_rate` and `metadata.financial_terms` for shared-profit terms
  - `limits` JSON nullable

### Execution Checklist

- [x] Finalize agency tenant number format.
- [x] Add unique agency number to central tenants.
- [x] Add central network membership migration/model.
- [x] Add central provider allocation migration/model.
- [x] Add agency network invite UI.
- [x] Add agency provider API sharing controls.
- [x] Add agency-to-merchant shared profit/discount/markup controls.
- [x] Add merchant join page by invitation token.
- [x] Allow merchant to select subset of offered provider APIs.
- [x] Show offered discount/markup terms to merchants before acceptance.
- [x] Confirm membership and selected allocations.
- [x] Dispatch join invitation email/notification.
- [x] Dispatch join confirmation email/notification to agency.
- [x] Add tests for invitation, provider offering, merchant subset acceptance, revoke/suspend.

### Phase 1 Notes

- Product issuance through merchant context is intentionally deferred to Phase 2.
- Notifications may start as Laravel notifications/events in Phase 1 and be expanded with WhatsApp in Phase 4.
- Phase 1 implementation stores invite and allocation data centrally, keeps agency credentials in agency tenant DBs, and lets merchants enable a subset of offered APIs.

---

## Phase 2 — Merchant Product Tests

### Goal

Validate that merchants can search, price, issue/book, and cancel/request cancellation using allocated agency providers.

### Checklist

- [x] Merchant flight search/pricing via allocated agency airline providers.
- [x] Merchant flight issuance with merchant wallet + agency provider wallet validation.
- [x] Merchant hotel search/checkRate/booking via allocated agency hotel providers.
- [x] Merchant hotel cancellation/request handling skipped/deferred until 3T cancellation behavior is confirmed.
- [x] Merchant insurance quote/issue/cancel via allocated agency insurance providers.
- [x] Source metadata stored in selected offer cache, order item details, and wallet metadata.
- [x] Tests for insufficient merchant wallet.
- [x] Tests for insufficient agency provider wallet.

### Phase 2 Notes

- Merchant network issuance now validates central merchant membership wallets and agency provider wallets before external issue/book calls for travel insurance, orange insurance, and hotels.
- Targeted validation passed for travel, orange, and hotel merchant network wallet coverage.
- Hotel cancellation/request handling is intentionally skipped/deferred pending final 3T cancellation confirmation behavior.

---

## Phase 3 — Order Page Redesign + Accounting UI

### Goal

Make order pages easier to use and make item information clear by product type. Build the full accounting section UI covering 19 pages across wallets, ledger, reports, settlement, and settings.

### Part A — Order Page Redesign

- [x] Redesign order summary hierarchy fully
- [x] Improve action menus by status and role
- [x] Show correct provider/source context for agency and merchant orders
- [x] Prepare data layout for PDFs

### Part B — Accounting Section UI

**Build order (each step depends on the previous):**

#### Step 1 — Foundation
- [x] `AccountingLayout` with persistent left sidebar (ScrollArea nav, active state, mobile Sheet collapse)
- [x] `AccountingBreadcrumb` component
- [x] `PageHeader` component (title + subtitle + action slot)
- [x] Global reusable components: `AccountingKpiCard`, `JournalEntrySheet`, `AmountDisplay`, `PeriodSelector`, `ExportButton`
- [x] Backend route group `routes/accounting.php` with all 19 routes
- [x] Permission middleware: `accounting.view` / `accounting.manage`

#### Step 2 — Dashboard (Page 1)
- [x] `AccountingDashboardController@index` — wallet summary, revenue, margin, VAT, receivables, recent journal entries, reconciliation status, alerts
- [x] `Pages/Accounting/Dashboard.tsx` — 4 KPI cards, provider wallet scroll cards, revenue bar chart, receivables/payables card, recent entries table, alert bar, period selector

#### Step 3 — Wallets (Pages 2–4)
- [x] `WalletController@index` — all tenant wallets list
- [x] `Pages/Accounting/Wallets/Index.tsx` — table with type badges, ledger account, balance, last activity
- [x] `WalletController@show` — single wallet with paginated transactions + 30-day balance history
- [x] `Pages/Accounting/Wallets/Show.tsx` — balance chart, transactions table, ledger Sheet link, order link, meta HoverCard
- [x] `WalletController@deposit` — fund wallet (POST)
- [x] Deposit `Dialog` inside `Show.tsx` — amount, currency, reference, notes, funding source

#### Step 4 — Provider Wallets (Page 5)
- [x] `ProviderWalletController@index` + `@show`
- [x] `Pages/Accounting/Providers/Index.tsx` — grid cards with progress bar, low-balance badge, fund button
- [x] `Pages/Accounting/Providers/Show.tsx` — provider wallet detail (reuses wallet show layout)

#### Step 5 — Journal Entries (Page 6) + JournalEntrySheet
- [x] `LedgerController@journalEntries` — paginated entries with lines, filters
- [x] `Pages/Accounting/Ledger/JournalEntries.tsx` — expandable rows, journal badges, balanced indicator, CSV export
- [x] `JournalEntrySheet` global component — right side panel showing full entry given a reference

#### Step 6 — Trial Balance (Page 7)
- [x] `LedgerController@trialBalance` — period-based trial balance from `TrialBalanceReport`
- [x] `Pages/Accounting/Ledger/TrialBalance.tsx` — grouped by account type, totals row, balanced badge, print + PDF export

#### Step 7 — Chart of Accounts + Account Detail (Pages 8–9)
- [x] `LedgerController@chartOfAccounts` + `@accountDetail`
- [x] `Pages/Accounting/Ledger/ChartOfAccounts.tsx` — tree hierarchy, current balance, link to detail
- [x] `Pages/Accounting/Ledger/AccountDetail.tsx` — opening balance, running balance per line, closing balance, period filter

#### Step 8 — Issuance History (Page 10)
- [x] `IssuanceHistoryController@index` — paginated issuances with financial summary
- [x] `Pages/Accounting/Issuance/History.tsx` — 4 KPI cards, filterable table, margin colour-coding, journal + order links

#### Step 9 — Settlement (Pages 11–12)
- [x] `SettlementController@index` + `@aging` + `@batch` + `@run`
- [x] `Pages/Accounting/Settlement/Index.tsx` — network: receivables + recent batches + run settlement; merchant: payables view
- [x] `Pages/Accounting/Settlement/MerchantAging.tsx` — aging buckets table, colour-coded 61–90 / 90+ columns, CSV export
- [x] `Pages/Accounting/Settlement/BatchDetail.tsx` — single settlement batch detail

#### Step 10 — Cancellations & Voids (Page 13)
- [x] `CancellationController@index` — paginated cancellation audit log
- [x] `Pages/Accounting/Cancellations/Index.tsx` — table with fee, net refunded, provider restored badge, reversal journal link

#### Step 11 — Reports (Pages 14–18)
- [x] `ReportController@index` + `@revenue` + `@grossMargin` + `@vat` + `@reconciliation`
- [x] `Pages/Accounting/Reports/Index.tsx` — hub with 6 report cards
- [x] `Pages/Accounting/Reports/Revenue.tsx` — KPI cards, stacked bar chart, per-product table, export
- [x] `Pages/Accounting/Reports/GrossMargin.tsx` — margin % line chart, colour-coded table, totals
- [x] `Pages/Accounting/Reports/VATSummary.tsx` — per-transaction VAT table, filing export
- [x] `Pages/Accounting/Reports/Reconciliation.tsx` — wallet vs ledger comparison, mismatch highlighting, re-run button

#### Step 12 — Accounting Settings (Page 19)
- [x] `AccountingSettingsController@index` + `@update`
- [x] `Pages/Accounting/Settings/Index.tsx` — tabbed: General, Wallet Thresholds, Revenue Recognition (read-only), Monthly Close, Reconciliation

---

## Phase 4 — Notifications & WhatsApp

### Goal

Add transactional notifications through email and WhatsApp.

### Checklist

- [x] Notification events for order created, ticket issued, hotel booked, policy issued.
- [ ] Cancellation requested/approved/finalized notifications.
- [x] WhatsApp text message integration.
- [ ] WhatsApp file sending integration for generated PDFs.
- [x] Queue notifications after DB commit.
- [x] Notification preferences and logs.

---

## Phase 5 — PDF / Printable Documents ✅ Completed

### Goal

Generate printable documents for order items and full orders.

### Checklist

- [x] Flight ticket PDF.
- [x] Hotel voucher / booking confirmation PDF.
- [x] Insurance policy PDF integration or generated fallback.
- [x] Full order PDF.
- [x] Download, print, and WhatsApp-send actions.

---

## Phase 6 — Tenant External APIs

### Goal

Allow agencies to integrate their own systems or mobile apps with their tenant.

### Checklist

- [ ] API auth model: Sanctum tokens or scoped API keys.
- [ ] Tenant-scoped API routes.
- [ ] API scopes/permissions.
- [ ] Rate limits.
- [ ] Idempotency keys for issuance endpoints.
- [ ] Audit logs.
- [ ] API documentation.

---

## Cross-Phase Risks

- Merchant issuance must never expose or copy agency provider credentials.
- Dual-wallet merchant flow must validate both merchant wallet and agency provider wallet before external API calls.
- Ledger/accounting refinements should be reviewed after merchant product tests clarify real transaction shape.
- Translation cleanup is deferred but should not be forgotten before production.
- Public storefront is intentionally deferred until core and merchant flows stabilize.
