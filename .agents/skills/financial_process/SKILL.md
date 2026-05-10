---
name: financial-process
description: "Financial processes including bavix/laravel-wallet tenant and provider wallets, order management, commission calculation, dual-wallet merchant issuance, and double-entry ledger posting."
license: MIT
metadata:
  author: booknow
---

# Financial Process – Wallets, Orders, Ledger

## Canonical Wallet Rule

Read `financial-and-wallet-system` before implementing any wallet, order payment, provider balance, refund, void, cancellation, ledger, or merchant issuance logic.

All wallets in TAMS must use `bavix/laravel-wallet`:

- Tenant/agency wallets.
- Merchant wallets.
- Airline provider wallets.
- Insurance provider wallets.
- Future hotel/eSIM provider wallets.

Do not extend or introduce custom balance/transaction tables when the data represents wallet activity.

Legacy provider-account models are migration sources only and should be deprecated:

- `AirlineAccount`
- `AirlineTransaction`
- `TenantInsuranceProviderAccount`
- `TenantInsuranceProviderTransaction`

## Wallets (bavix/laravel-wallet)
- Each tenant (agency) has one wallet per currency (LYD, USD, EUR).
- Provider models also have wallets to track external provider/API balances.
- Use wallet package transactions and metadata for deposits, withdrawals, refunds, and reversals.
- Preferred methods include `depositFloat()`, `withdrawFloat()`, `forceWithdrawFloat()`, `canWithdrawFloat()`.
- Never update a wallet balance column manually.

## Orders & Order Items
- `orders`: owner polymorphic (user), status, grand_total, currency, payment_method, etc.
- `order_items`: product_type (flight, hotel, insurance, esim), product_subtype (oneway, return, compulsory, travel, orange), net_fare, taxes (JSON), total_amount, commission_percent, commission_amount, product_details (JSON), provider_reference, wallet_transaction_id, airline_transaction_id, ledger_entry_id.

## Financial Flow (Booking)
After successful API booking (ticket issued or policy created):
1. Create `Order` and `OrderItem` in database transaction.
2. Calculate commission based on provider settings.
3. Determine financial source:
   - If agency uses own credentials → validate and deduct the selected provider wallet after successful issuance.
   - If default agency supply is used → validate provider/source rules and record commission owed to default agency.
   - If merchant uses an agency-network provider → validate merchant wallet and agency provider wallet before issuance, then deduct both after successful issuance.
4. Post to ledger (abivia/ledger) – journal entries for revenue, expense, liabilities.
5. Log status change in `order_status_log`.

## Ledger Management

The financial process does not end at wallet transactions.

Required sequence:

```text
Wallet balance validation
→ wallet transaction
→ order/order item update
→ ledger posting
```

Wallet transactions record balance movement. Ledger entries provide the tenant accounting record for reporting and reconciliation.

Use existing ledger actions such as `PostToLedger` where possible. Refunds, voids, and cancellations must also create the corresponding ledger reversal when the original transaction affected accounting.

## Balance Validation Before Issuance

External API issuance must not be called until required balances are confirmed.

Agency issuance:

1. Validate selected provider wallet balance.
2. Call external API.
3. Deduct provider wallet with metadata.
4. Create order and ledger records.

Merchant issuance through agency network:

1. Validate merchant wallet balance.
2. Validate agency provider wallet balance.
3. Call external API with agency provider credentials.
4. Deduct merchant wallet.
5. Deduct agency provider wallet.
6. Create merchant order and ledger/settlement records.

If any balance validation fails, do not call the external provider API.

## Commission on Insurance
- Stored in `tenant_insurance_providers.commission_compulsory`, `commission_travel`, `commission_orange`.
- Commission amount = net_premium * rate / 100.

## Required Wallet Metadata

Every wallet transaction must include reconciliation metadata:

```php
[
    'type' => 'booking_payment|provider_issuance_cost|provider_deposit|refund|void|cancellation',
    'tenant_id' => tenant()?->id,
    'order_id' => $orderId,
    'order_item_id' => $orderItemId,
    'product_type' => 'flight|insurance|hotel|sim',
    'provider_id' => $providerId,
    'provider_reference' => $providerReference,
    'network_membership_id' => $networkMembershipId ?? null,
]
```

## Reconciliation
- Central admin can compare sum of order totals vs wallet withdrawals.
- Use `agency_wallet_transactions` central table for cross‑tenant tracking (future).

## Reporting
- Daily sales summary, commission report, tax breakdown, wallet history.
- All via `order_items` queries – product‑agnostic.
