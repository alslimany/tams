# Booknow — Accounting Audit Recording & Analysis Plan

**Purpose:** Record every financial operation the user performs end-to-end, capture all numbers at every layer, write a structured log report, then send that report for expert analysis to find accounting gaps, wrong entries, or missing records.  
**Stack:** Laravel · Inertia · React · bavix/laravel-wallet · abivia/ledger  
**Recording layer:** HTTP Middleware (request/response interception)  
**Storage:** Log files only (no DB)  
**Output:** A single structured JSON log file per test session  
**Executor:** AI agent (Claude Sonnet 4.6) runs the tests and produces the report. Human reviews before sending for analysis.

---

## How This Works — Big Picture

```
User/Agent performs action in browser
        ↓
Inertia HTTP Request hits Laravel
        ↓
[AuditRecorderMiddleware] captures:
  - Route, method, Inertia component
  - Request payload (sanitised)
  - Authenticated user + tenant
        ↓
Action executes (issuance / cancellation / settlement / deposit)
        ↓
[AuditRecorderMiddleware] captures response:
  - HTTP status
  - Inertia page props returned
        ↓
[AccountingSnapshotListener] fires on key events:
  - Wallet balances BEFORE and AFTER
  - Journal entries posted (debit/credit lines)
  - Order created (product type, amounts)
  - Provider API call + response (reference, cost)
        ↓
All data written to: storage/logs/audit/session_{id}.json
        ↓
[AuditReportGenerator] reads session file
  and compiles a human+AI-readable report
        ↓
Human reviews → sends to Claude for analysis
```

---

## Phase 1 — Build the Recording Infrastructure

### 1.1 Directory Structure

```
app/
├── Audit/
│   ├── AuditRecorderMiddleware.php       ← HTTP layer recorder
│   ├── AccountingSnapshotService.php     ← captures wallet+ledger state
│   ├── AuditSessionManager.php           ← manages session ID + log file
│   └── AuditReportGenerator.php          ← compiles final report
│
config/
│   └── audit.php                         ← enable/disable + settings
│
storage/logs/audit/
│   └── session_{uuid}.json               ← one file per test session
```

### 1.2 Config (`config/audit.php`)

- Master switch: `ACCOUNTING_AUDIT_ENABLED` (default `false`)
- `watch_routes`: route prefixes to intercept
- `capture_provider_api`: whether to capture full provider responses
- `log_path`: `storage/logs/audit`
- `redact_fields`: sensitive fields stripped from payloads

### 1.3 AuditSessionManager

Static class managing session lifecycle:
- `start(label)` → creates UUID session, records `session_start` event
- `record(eventType, data)` → appends event with seq + timestamp + elapsed_ms
- `flush()` → writes full session JSON to disk
- `sessionId()` / `events()` → accessors

### 1.4 AuditRecorderMiddleware

Fires on every watched route:
1. Captures wallet+ledger snapshot **before** request
2. Records `http_request` event (method, URL, route name, payload)
3. Executes request
4. Captures snapshot **after** request
5. Records `http_response` event (status, Inertia props)
6. Records wallet diff (before/after/change per wallet)
7. Flushes session to disk

### 1.5 AccountingSnapshotService

Point-in-time capture of:
- All agency wallets (slug, balance, currency, ledger account)
- All provider wallets (TenantProvider model)
- All merchant wallets (Tenant with type=merchant)
- Key ledger account balances (1110–5500)
- Open orders (pending/confirmed, last 10)

---

## Phase 2 — Event Hooks

### 2.1 WalletTransactionAuditListener

Listens to `TransactionCreatedEventInterface` (bavix):
- Records `wallet_transaction` event with wallet slug, type, amount, balance_after, meta

### 2.2 JournalEntryAuditObserver

Observes `JournalEntry::created` (abivia):
- Records `journal_entry_posted` with all debit/credit lines, totals, is_balanced flag

### 2.3 OrderAuditObserver

Observes `Order::created`:
- Records `order_created` with selling price, VAT, cost, margin, commission

---

## Phase 3 — AuditReportGenerator

Reads `session_{id}.json` and compiles:

| Section | Description |
|---|---|
| `meta` | Session ID, label, tenant, timing |
| `flow_summary` | Counts of each event type |
| `financial_numbers` | Totals by product type (selling, VAT, net, cost, margin) |
| `wallet_movements` | All wallet transactions with ledger meta |
| `ledger_entries` | All journal entries with lines |
| `balance_checks` | Wallet balance vs ledger account balance (MATCHED/MISMATCH) |
| `accounting_checks` | 7 automated checks (see below) |
| `provider_api` | API calls, successes, failures |
| `anomalies` | Auto-detected issues |

### Accounting Checks (7)

1. **all_entries_balanced** — every journal entry debits = credits
2. **all_withdrawals_have_ledger_meta** — every wallet withdrawal carries ledger account metadata
3. **every_order_has_journal_entry** — every order has a matching journal entry reference
4. **provider_success_has_wallet_deduction** — every provider API success has a wallet withdrawal
5. **revenue_is_net_of_vat** — revenue account lines match selling price minus VAT
6. **no_negative_gross_margin** — no order has negative gross margin
7. **no_duplicate_journal_entries** — no duplicate journal entry references

---

## Phase 4 — Artisan Commands

| Command | Description |
|---|---|
| `php artisan audit:start {label}` | Start a named session, prints session ID |
| `php artisan audit:report {session_id}` | Generate report JSON + print console summary |
| `php artisan audit:list` | List all recorded sessions |

---

## Phase 5 — Test Scenarios

### Scenario 1 — Direct Agency: Airline Ticket (Cash Customer)
- Deposit 10,000 LYD into airline provider wallet
- Book flight: selling 1,200 LYD / cost 950 LYD / VAT 10%
- Checks: wallet deducted (1210), revenue to 4100, cost to 5100, balanced

### Scenario 2 — Direct Agency: Hotel Booking
- Deposit 5,000 LYD into hotel provider wallet
- Book room: selling 500 LYD / cost 400 LYD / VAT 10%
- Checks: wallet deducted (1220), revenue to 4200, cost to 5200

### Scenario 3 — Direct Agency: Insurance Policy
- Deposit 3,000 LYD into insurance provider wallet
- Issue policy: selling 200 LYD / cost 150 LYD / VAT 10%
- Checks: wallet deducted (1230), revenue to 4300, cost to 5300

### Scenario 4 — Direct Agency: Multi-Product in One Session
- Issue 1 airline + 1 hotel + 1 insurance in same session
- Checks: each posts to its own sub-journal, all provider wallets deducted independently, trial balance balanced

### Scenario 5 — Network Agency: Merchant Issuance
- Agency deposits 10,000 LYD into airline provider wallet
- Merchant deposits 5,000 LYD into merchant wallet
- Merchant books flight: selling 1,200 / wholesale 1,000 / cost 950 / VAT 10%
- Checks: merchant wallet deducted 1,000; agency provider wallet deducted 950; both ledgers balanced

### Scenario 6 — Cancellation Without Fee
- Issue airline ticket → cancel immediately
- Checks: provider wallet restored, revenue 4100 net = 0, VAT 2400 net = 0

### Scenario 7 — Cancellation With Fee
- Issue airline ticket → cancel with 50 LYD fee
- Checks: revenue reversed net of fee, 4700 shows 50 LYD, customer refunded 1,150 LYD

### Scenario 8 — Merchant Settlement Run
- Run Scenario 5 → navigate to Settlement → run settlement for merchant
- Checks: merchant payable (2200) cleared, agency receivable (1320) cleared, STL sub-journal entry posted

### Scenario 9 — Full Reconciliation
- Run Scenarios 1 + 2 + 3 back to back
- Navigate to Reports > Reconciliation
- Checks: all wallet/ledger pairs MATCHED, no anomalies, trial balance balanced

---

## Phase 6 — How to Send the Report for Analysis

### Step 1 — Generate report
```bash
php artisan audit:report {session_id}
# Output: storage/logs/audit/report_{session_id}.json
```

### Step 2 — Human reviews console summary

### Step 3 — Send to Claude
Paste `report_{session_id}.json` with this prompt:

```
Here is an accounting audit report from my Booknow travel SaaS platform.
The platform uses double-entry accounting (abivia/ledger) and wallet management (bavix/laravel-wallet).
Revenue model is gross. All three agency types are in use.

Please analyse this report and tell me:
1. Are all accounting entries correct and complete?
2. Are there any missing journal entries or wrong account codes?
3. Are VAT amounts calculated and posted correctly?
4. Are wallet balances matching their ledger counterparts?
5. Are any financial numbers (margin, commission, markup) inconsistent?
6. Are there any anomalies I should investigate?
7. What is missing from the accounting records compared to the plan?

Report:
[paste report JSON here]
```

---

## Agent Checklist

### Infrastructure
- [ ] `config/audit.php` exists and `ACCOUNTING_AUDIT_ENABLED=true` in `.env`
- [ ] `AuditRecorderMiddleware` registered and fires on watched routes
- [ ] `WalletTransactionAuditListener` registered
- [ ] `JournalEntryAuditObserver` registered
- [ ] `OrderAuditObserver` registered
- [ ] `storage/logs/audit/` is writable
- [ ] `php artisan audit:list` returns no error

### Per-Scenario
- [ ] Session JSON file created
- [ ] Contains `wallet_transaction`, `journal_entry_posted`, `order_created`, `provider_api_success` events
- [ ] Report JSON generated without errors
- [ ] `accounting_checks.all_entries_balanced.passed = true`
- [ ] `accounting_checks.every_order_has_journal_entry.passed = true`
- [ ] `balance_checks` covers every provider wallet used
- [ ] `anomalies` array is empty or documented

### Before Sending to Claude
- [ ] Human reviewed console summary
- [ ] Test steps completed correctly (no missing actions)
- [ ] Report file under 500 KB
- [ ] `ACCOUNTING_AUDIT_ENABLED` set back to `false` after testing

---

## Important Rules

1. **Never enable `ACCOUNTING_AUDIT_ENABLED=true` in production.**
2. **Run each scenario in isolation** — fresh session per scenario.
3. **Use realistic amounts** — not round numbers, to catch VAT rounding edge cases.
4. **Do not delete session files** after report generation.
5. **If a PHP exception occurs**, record it manually via `AuditSessionManager::record('exception', [...])`.
6. **The audit layer must not alter any financial data** — read-only, append-only.
