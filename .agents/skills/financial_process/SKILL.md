---
name: financial-process
description: "Financial processes including tenant wallets (bavix/laravel-wallet), order management, commission calculation, and double-entry ledger (abivia/ledger) for revenue and expense tracking."
license: MIT
metadata:
  author: booknow
---

# Financial Process – Wallets, Orders, Ledger

## Wallets (bavix/laravel-wallet)
- Each tenant (agency) has one wallet per currency (LYD, USD, EUR).
- Wallets live in the **tenant database** (for now; future may move to central for merchant model).
- Methods: `deposit()`, `withdraw()`, `canWithdraw()`, `balance`, `transactions`.

## Orders & Order Items
- `orders`: owner polymorphic (user), status, grand_total, currency, payment_method, etc.
- `order_items`: product_type (flight, hotel, insurance, esim), product_subtype (oneway, return, compulsory, travel, orange), net_fare, taxes (JSON), total_amount, commission_percent, commission_amount, product_details (JSON), provider_reference, wallet_transaction_id, airline_transaction_id, ledger_entry_id.

## Financial Flow (Booking)
After successful API booking (ticket issued or policy created):
1. Create `Order` and `OrderItem` in database transaction.
2. Calculate commission based on provider settings.
3. Determine financial source:
   - If agency uses own credentials → record in `airline_accounts` (external), no wallet movement.
   - Else (default agency supply) → deduct from agency's wallet via `ProcessWalletTransactions` action.
4. Post to ledger (abivia/ledger) – journal entries for revenue, expense, liabilities.
5. Log status change in `order_status_log`.

## Commission on Insurance
- Stored in `tenant_insurance_providers.commission_compulsory`, `commission_travel`, `commission_orange`.
- Commission amount = net_premium * rate / 100.

## Reconciliation
- Central admin can compare sum of order totals vs wallet withdrawals.
- Use `agency_wallet_transactions` central table for cross‑tenant tracking (future).

## Reporting
- Daily sales summary, commission report, tax breakdown, wallet history.
- All via `order_items` queries – product‑agnostic.