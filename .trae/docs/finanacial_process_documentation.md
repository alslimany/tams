# Financial Process Documentation: From Booking to Ledger

## 1. Overview

This document describes the complete financial flow in the Booknow V2 platform, from the moment a booking is confirmed (ticket issued) through wallet transactions, accounting ledger entries, and sales reporting. It covers both agency models:

- **Agency uses its own airline credentials** – no internal wallet movement; transactions recorded in external `airline_accounts`.
- **Agency uses master agency supply** – internal wallet deducted (multi‑currency), commission may be deposited back.

The system uses:
- `bavix/laravel-wallet` for internal wallet management.
- `abivia/ledger` for double‑entry accounting (general ledger).
- Custom `order_items` and `orders` tables for commercial records.
- JSON fields for taxes and product details.

All financial flows are atomic (database transactions) and auditable.

---

## 2. Data Model (Core Tables)

### 2.1 Central (Landlord) Tables – Global
- `route_availability_cache` – learns which airlines serve which routes (optional, for performance).
- `flight_schedule_cache` – lowest price per date per route (for calendar hints).
- `exchange_rates` – if manual exchange between wallets is enabled (optional).

### 2.2 Tenant Database Tables (per agency)

#### `orders`
| Column | Type | Description |
|--------|------|-------------|
| id | UUID | Primary key |
| owner_type, owner_id | polymorphic | e.g., `App\Models\User` (the agency user) |
| number | string(20) | Unique order number (e.g., `AAA0002BA`) |
| status | string | pending, confirmed, issued, cancelled, refunded |
| issued_at | timestamp | When ticket/booking was issued |
| grand_total | decimal(15,2) | Total order value (sum of all items) |
| currency | string(3) | Order currency (may differ from item currencies; use exchange rates) |
| payment_method | string | invoice, cash, wallet, airline_account |
| payment_reference | string | Invoice number or transaction ID |
| contact | JSON | Customer contact snapshot |

#### `order_items`
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Auto‑increment |
| order_id | UUID | FK to `orders.id` |
| product_type | enum | flight, hotel, insurance, esim, other |
| product_subtype | string(50) | e.g., oneway, return, room_only |
| currency | string(3) | Currency of this item |
| exchange_rate | decimal(10,6) | Rate applied if different from order currency |
| net_fare | decimal(15,2) | Base price before taxes |
| taxes | JSON | `[{"code":"ST","amount":1.00,"description":"booking tax"}, ...]` |
| total_tax | decimal(15,2) | Sum of taxes (denormalised) |
| total_amount | decimal(15,2) | net_fare + total_tax |
| commission_percent | decimal(5,2) | % from tenant_provider (airline) or supplier |
| commission_amount | decimal(15,2) | net_fare * commission_percent / 100 |
| net_after_commission | decimal(15,2) | net_fare - commission_amount |
| agent_commission | decimal(15,2) | Commission earned by selling agency (if split) |
| status | string | issued, refunded, cancelled |
| transaction_type | enum | purchase, refund, penalty, commission |
| product_details | JSON | Product‑specific data (flights, hotel nights, eSIM details) |
| provider_reference | string(191) | PNR, hotel booking ID |
| ticket_number | string(50) | For flights (first coupon) |
| wallet_transaction_id | char(36) | Link to `bavix/laravel-wallet` transaction (if internal wallet used) |
| airline_transaction_id | bigint | Link to `airline_transactions` (if own credentials) |
| ledger_entry_id | bigint | Link to `abivia/ledger` journal entry |

#### `airline_accounts` & `airline_transactions` (external)
- Used when agency uses its own airline credentials.
- `airline_accounts` stores current balance per (tenant_provider_id, currency).
- `airline_transactions` records debits/credits (ticket costs, refunds) linked to order_items.

#### `wallet` tables (from `bavix/laravel-wallet`)
- `wallets` – balance per tenant per currency.
- `transactions` – each deposit/withdrawal with `meta` JSON storing `order_id` and `order_item_id`.

#### `ledger` tables (from `abivia/ledger`)
- Journals, entries, accounts – every financial event posted as double‑entry.

---

## 3. Financial Flow – Step by Step

### 3.1 Booking Confirmation (after successful ticket issuance)

**Trigger:** After `BaseVidecomAirline::issueTicket()` returns valid XML response.

**Actions inside a database transaction:**

1. **Parse XML** into structured DTO (PNR, ticket numbers, fare, taxes, payment reference, passenger/segment details).
2. **Create `order`** – generate unique `number`, set `status = 'issued'`, `issued_at = now()`, store contact snapshot.
3. **Create `order_items`** – one per passenger per segment (or per product). For flights:
   - `product_type = 'flight'`
   - `net_fare` = fare from `<FareStore>` (base fare only, excluding taxes)
   - `taxes` = array from `<PaxTax>` elements (include code, amount, description)
   - `total_tax` = sum of tax amounts
   - `total_amount` = `net_fare + total_tax`
   - `product_details` = flight segment data (airline, flight, date, times, passenger name, etc.)
   - `provider_reference` = PNR
   - `ticket_number` = from `<TKT>`
4. **Determine commission**:
   - Look up `tenant_provider` for this airline.
   - Determine if route is international (compare country of origin and destination using a country‑airport mapping table).
   - Use `commission_domestic` or `commission_international`.
   - Calculate `commission_amount = net_fare * commission_percent / 100`.
   - Set `net_after_commission = net_fare - commission_amount`.
5. **Determine financial source**:
   - If agency has an active `tenant_provider` for this airline and is configured to use its own credentials:
     - **No internal wallet movement.**
     - Create `airline_transaction` record: `type = 'ticket_cost'`, `amount = -total_amount` (or -net_fare depending on airline contract), update airline account balance.
     - Store `airline_transaction_id` in `order_item`.
   - Else (use master agency supply):
     - **Deduct from agency’s internal wallet** (multi‑currency).
     - Call `$agencyWallet->withdraw($order_item->total_amount, ['order_id' => $order->id, 'order_item_id' => $item->id, 'type' => 'ticket_purchase'])`.
     - Store returned `transaction_id` in `order_item.wallet_transaction_id`.
     - **Commission handling** (if commission_amount > 0):
       - The commission reduces the effective cost to the agency. Instead of a separate deposit, you can record the commission as a **second transaction** (deposit) back to the same wallet:
         `$agencyWallet->deposit($commission_amount, ['order_item_id' => $item->id, 'type' => 'commission_earned'])`.
       - This makes accounting clear: withdraw full total_amount, then deposit commission as income.
6. **Post to ledger** (abivia/ledger):
   - Create a journal with appropriate accounts. Example for a flight sale using master agency supply:
     - Debit: `Agency Receivable` (or `Agency Wallet Asset`) – total_amount
     - Credit: `Sales Revenue – Flights` – net_fare
     - Credit: `Tax Payable – [tax code]` – each tax amount
     - Credit: `Commission Expense` – commission_amount (if commission is an expense to the agency) OR treat commission as a reduction of revenue.
   - The exact chart of accounts must be defined. For simplicity, we can use:
     - `Asset:Wallet:Tenant` – decreases (credit) when withdraw.
     - `Revenue:Flights` – increase (credit).
     - `Liability:Taxes` – increase (credit) until paid.
     - `Expense:Commission` – increase (debit) when commission is paid out.
   - Store `ledger_entry_id` in `order_item`.
7. **Log status change** in `order_status_log`.
8. **Commit transaction**.

### 3.2 Handling Refunds (Future)

- When an order item is refunded, create a new `order` (type = 'refund') linked via `parent_id`.
- Create `order_item` with negative amounts (or a separate `refund_items` table).
- Reverse the wallet transaction: `$agencyWallet->deposit($refund_amount, ...)`.
- Post reversing journal entries.
- Update original order item status to `refunded`.

---

## 4. Commission Calculation Detail

**Source:** `tenant_providers` table columns:
- `commission_domestic` (percentage)
- `commission_international` (percentage)

**International determination:**
- Requires a table `airport_countries` (airport_code, country_code).
- Compare country of origin and destination. If different → international.

**Calculation:**
```php
$netFare = $orderItem->net_fare;
$commissionPercent = $route->isInternational() ? $provider->commission_international : $provider->commission_domestic;
$commissionAmount = round($netFare * $commissionPercent / 100, 2);
$netAfterCommission = $netFare - $commissionAmount;
```

**Note:** Commission is applied on net fare (excluding taxes). This matches typical airline practice.

---

## 5. Wallet Integration (bavix/laravel-wallet)

### Setup per tenant
Each tenant (agency) can have multiple wallets (one per currency). Use the package’s native `HasWallets` trait on the `Tenant` model (or the `User` model representing the agency owner).

### Withdraw
```php
$wallet = $tenant->getWallet($currency);
$transaction = $wallet->withdraw($amount, [
    'order_id' => $order->id,
    'order_item_id' => $item->id,
    'type' => 'ticket_purchase',
    'description' => "Flight {$item->product_details['airline']}{$item->product_details['flight_number']} for {$item->product_details['passenger_name']}"
]);
```

### Deposit (for commission)
```php
$wallet->deposit($commissionAmount, [
    'order_id' => $order->id,
    'order_item_id' => $item->id,
    'type' => 'commission_earned',
    'description' => "Commission on flight sale"
]);
```

### Balance queries
```php
$balance = $tenant->getWallet('LYD')->balance;
```

The package stores all transactions in a `transactions` table with `meta` JSON column – this is where we store `order_id` and `order_item_id`.

---

## 6. Ledger Integration (abivia/ledger)

We will not recreate the entire ledger system; we will use the `abivia/ledger` package as the source of truth for double‑entry accounting.

### Basic concepts
- **Journal** – a financial event (e.g., sale of a ticket).
- **Entry** – a line in a journal (debit or credit).
- **Ledger** – collection of accounts (balance sheet, income statement).

### Chart of Accounts (example for travel agency)
| Code | Name | Type |
|------|------|------|
| 1100 | Cash – Master Agency | Asset |
| 1200 | Accounts Receivable – Agencies | Asset |
| 1300 | Wallet Assets – Tenant Wallets | Asset |
| 2100 | IATA Payable | Liability |
| 2200 | Tax Payable – ST | Liability |
| 2201 | Tax Payable – WV | Liability |
| ... | ... | ... |
| 3100 | Revenue – Flights | Income |
| 4100 | Cost of Sales – Flights | Expense |
| 5100 | Commission Income | Income |
| 6100 | Commission Expense | Expense |

### Posting a sale (master agency supply)
```php
use Abivia\Ledger\Ledger;

$journal = new Journal();
$journal->journal_type = 'operation';
$journal->source = 'order_' . $order->id;
$journal->description = "Flight sale for order {$order->number}";

// Debit: Wallet Asset (decrease) – outflow of money from agency
$entry1 = $journal->addEntry();
$entry1->account = '1300'; // Wallet Assets – Tenant Wallets
$entry1->amount = $totalAmount;
$entry1->direction = 'credit'; // credit reduces asset

// Credit: Revenue – Flights
$entry2 = $journal->addEntry();
$entry2->account = '3100';
$entry2->amount = $netFare;
$entry2->direction = 'credit';

// Credit: Tax Payables (each tax code)
foreach ($orderItem->taxes as $tax) {
    $entry = $journal->addEntry();
    $entry->account = '2200_' . $tax['code']; // or dynamic mapping
    $entry->amount = $tax['amount'];
    $entry->direction = 'credit';
}

// Credit: Commission Expense (if commission is an expense)
if ($commissionAmount > 0) {
    $entry = $journal->addEntry();
    $entry->account = '6100';
    $entry->amount = $commissionAmount;
    $entry->direction = 'debit'; // expense increases with debit
}

Ledger::post($journal);
```

Store the journal ID in `order_item.ledger_entry_id`.

---

## 7. Sales Reports

### 7.1 Report: Daily Sales Summary (per agency)
```sql
SELECT 
    DATE(o.issued_at) as sale_date,
    oi.product_type,
    COUNT(oi.id) as items_sold,
    SUM(oi.net_fare) as total_net_fare,
    SUM(oi.total_tax) as total_tax,
    SUM(oi.total_amount) as total_amount,
    SUM(oi.commission_amount) as total_commission
FROM orders o
JOIN order_items oi ON oi.order_id = o.id
WHERE o.issued_at BETWEEN :start AND :end
  AND o.owner_id = :agency_user_id
  AND o.status = 'issued'
GROUP BY sale_date, oi.product_type
ORDER BY sale_date DESC;
```

### 7.2 Report: Commission Report
```sql
SELECT 
    oi.id,
    oi.product_type,
    oi.net_fare,
    oi.commission_percent,
    oi.commission_amount,
    oi.net_after_commission,
    CASE 
        WHEN oi.wallet_transaction_id IS NOT NULL THEN 'master_agency_supply'
        WHEN oi.airline_transaction_id IS NOT NULL THEN 'own_credentials'
    END as supply_type
FROM order_items oi
JOIN orders o ON o.id = oi.order_id
WHERE o.issued_at BETWEEN :start AND :end
  AND o.owner_id = :agency_user_id;
```

### 7.3 Report: Tax Breakdown
```php
foreach ($orderItems as $item) {
    $taxes = json_decode($item->taxes, true);
    foreach ($taxes as $tax) {
        $taxSummary[$tax['code']] = ($taxSummary[$tax['code']] ?? 0) + $tax['amount'];
    }
}
```

### 7.4 Report: Wallet Balance & Transaction History
- Use `bavix/laravel-wallet` methods:
  - `$wallet->transactions` to get all transactions.
  - Filter by `meta->order_id` to isolate booking related movements.

### 7.5 Report: Reconciliation Report (Order vs Transactions)
```sql
SELECT 
    oi.id,
    oi.total_amount as order_amount,
    wt.amount as wallet_amount,
    (oi.total_amount - wt.amount) as difference
FROM order_items oi
LEFT JOIN transactions wt ON wt.meta->>'$.order_item_id' = oi.id
WHERE oi.wallet_transaction_id IS NOT NULL
  AND oi.total_amount != wt.amount;
```

---

## 8. Accounting Reports (using ledger)

With `abivia/ledger`, you can generate:
- **Trial Balance** – sum of debits and credits per account.
- **Profit & Loss** – revenue minus expenses over a period.
- **Balance Sheet** – assets, liabilities, equity at a point in time.

These reports are provided by the package’s API. You can schedule them daily and store PDFs for agencies.

---

## 9. Error Handling & Idempotency

- Always wrap order creation and financial transactions in a **database transaction**.
- Use unique constraints on `orders.number` and `order_items.provider_reference` (PNR) to prevent duplicates.
- Before withdrawing from wallet, check sufficient balance using `$wallet->canWithdraw($amount)`.
- Log every step with `Log::info()` including the PNR and order number.
- If wallet withdrawal fails, the entire booking should be voided (call `EZV*R` on the PNR) to avoid orphaned ticket.

---

## 10. Implementation Checklist for AI

- [ ] Create migrations for `airline_accounts`, `airline_transactions` (tenant DB).
- [ ] Add commission columns to `tenant_providers`.
- [ ] Create `airport_countries` table (central DB) for international determination.
- [ ] Implement `RouteInternationalService` to determine if a route is international.
- [ ] Modify `BaseVidecomAirline::issueTicket()` to call a new `ProcessBookingFinancials` action after successful issuance.
- [ ] Implement `ProcessBookingFinancials` (multi‑tenant aware):
  - Parse XML into DTO.
  - Create order & order_items.
  - Calculate commission based on tenant_provider.
  - Determine financial source (own credentials vs master supply).
  - Execute wallet withdrawal/deposit (if master supply).
  - Create airline_transaction records (if own credentials).
  - Post to ledger (abivia/ledger).
  - Log status.
- [ ] Write tests covering both agency models.
- [ ] Build report endpoints and UI (Inertia + React) for:
  - Daily sales summary
  - Commission report
  - Tax breakdown report
  - Wallet transaction history
  - Reconciliation report (admin only)

