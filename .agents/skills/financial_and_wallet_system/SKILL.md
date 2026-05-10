---
name: financial-and-wallet-system
description: "Canonical TAMS financial and wallet rules. Enforces bavix/laravel-wallet for tenant wallets, provider wallets, agency/merchant issuance, provider deposits, balance validation before issuance, order financial records, and ledger posting."
license: MIT
metadata:
  author: booknow
---

# TAMS Financial & Wallet System

## Mandatory Rule

All wallets in TAMS must use `bavix/laravel-wallet`. No custom wallet balance tables should be extended or introduced.

This applies to:

- Tenant agency wallet.
- Merchant wallet.
- Tenant provider wallet.
- Tenant insurance provider wallet.
- Future hotel/eSIM provider wallets.

---

## 1. Canonical Wallet Package

Use `bavix/laravel-wallet` for every balance-holding entity.

Required behavior:

- Use wallet package transactions table for all deposits/withdrawals.
- Use package methods such as `depositFloat`, `withdrawFloat`, `forceWithdrawFloat`, `canWithdrawFloat`.
- Store metadata in wallet transactions for traceability.
- Do not create new custom account/transaction tables.

---

## 2. Provider Wallets

Every API integration provider must have a wallet.

Provider examples:

- `TenantProvider` for airline APIs.
- `TenantInsuranceProvider` for insurance APIs.
- Future `TenantHotelProvider`.
- Future `TenantSimProvider`.

Implementation expectation:

```php
use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Traits\HasWallet;

class TenantProvider extends Model implements Wallet
{
    use HasWallet;
}
```

Provider wallet purpose:

- Simulate/manual-track funds deposited with an external API/provider.
- Validate the provider has enough external balance before issuance.
- Deduct provider wallet when issuance consumes provider balance.
- Log deposits with amount, date, reference number, and metadata.

---

## 3. Legacy Tables to Deprecate

These are legacy/custom wallet substitutes and must not be extended:

- `airline_accounts`
- `airline_transactions`
- `tenant_insurance_provider_accounts`
- `tenant_insurance_provider_transactions`

Models to deprecate:

- `App\Models\Tenant\AirlineAccount`
- `App\Models\Tenant\AirlineTransaction`
- `App\Models\Tenant\TenantInsuranceProviderAccount`
- `App\Models\Tenant\TenantInsuranceProviderTransaction`

If a task touches these models, the AI must stop and propose migration to bavix wallet instead of adding new features to them.

---

## 4. Manual Provider Deposit Flow

Agency admins may manually deposit amounts into a provider wallet to mirror external provider deposits.

Required fields:

- Amount.
- Currency.
- Deposit date.
- Reference number.
- Provider ID.
- Optional notes.

Wallet transaction metadata must include:

```php
[
    'type' => 'provider_deposit',
    'provider_type' => 'airline|insurance|hotel|sim',
    'provider_id' => $provider->id,
    'deposit_date' => $depositDate,
    'reference_number' => $referenceNumber,
    'notes' => $notes,
]
```

Never update a `balance` column manually.

---

## 5. Balance Validation Before Issuance

Balance validation must happen before any external API issuance call.

For agency issuing through its own provider:

1. Validate agency tenant wallet has enough customer/order balance if required by business flow.
2. Validate selected provider wallet has enough external provider balance.
3. Only then call external API.

For merchant issuing through agency network provider:

1. Validate merchant wallet has enough balance.
2. Validate agency provider wallet has enough balance.
3. Only then call external API using the agency provider credentials.

If any validation fails, do not call the external API.

---

## 6. Agency Tenant Issuance Flow

When an agency tenant issues through its own provider:

```text
Validate agency/provider permissions
→ Validate provider wallet balance
→ Call external API
→ Deduct provider wallet
→ Create Order + OrderItem
→ Calculate commission
→ Post ledger entries
→ Redirect to confirmation page
```

Provider wallet withdrawal metadata:

```php
[
    'type' => 'provider_issuance_cost',
    'order_id' => $order->id,
    'order_item_id' => $item->id,
    'provider_reference' => $pnrOrPolicyId,
    'product_type' => 'flight|insurance|hotel|sim',
]
```

---

## 7. Merchant Tenant Issuance Flow

Merchant issuance through an agency provider requires two wallet checks and two deductions.

Required sequence:

```text
Merchant selects offer from agency network provider
→ Validate merchant wallet balance
→ Validate agency provider wallet balance
→ Call external API using agency provider credentials
→ Deduct merchant wallet
→ Deduct agency provider wallet
→ Create merchant Order + OrderItem
→ Record agency-side provider wallet transaction metadata
→ Post merchant ledger entries
→ Post/record agency-side settlement/commission entries as needed
```

Important:

- Merchant wallet deduction represents the amount paid by merchant.
- Agency provider wallet deduction represents provider/API balance consumed.
- Both sides must be traceable.
- The order belongs to the merchant tenant context.
- The provider credentials belong to the agency tenant context.

---

## 8. Transaction Metadata Requirements

Every wallet transaction must contain enough metadata to reconcile.

Minimum metadata:

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

---

## 9. Orders and Order Items

Every successful issuance creates:

- One `Order`.
- One or more `OrderItem` records.

Order item must store:

- `product_type`.
- `product_subtype`.
- `provider`.
- `provider_reference`.
- `net_fare` / `net_premium`.
- `taxes` JSON.
- `total_amount`.
- `commission_percent`.
- `commission_amount`.
- Wallet transaction UUID(s).
- Ledger entry ID(s).
- Source provider context (own, default agency, network agency).

---

## 10. Ledger Rules

Wallet transactions track money movement.
Ledger entries track accounting impact.

Every financial process must move through this sequence:

```text
Wallet balance validation
→ wallet transaction
→ order/order item update
→ ledger posting
```

Do not stop at wallet transactions when the movement affects tenant accounting. The tenant must have ledger entries that accountants can use for reporting and reconciliation.

Use existing actions where possible:

- `ProcessWalletTransactions`
- `PostToLedger`
- `CreateOrderFromBookingData`
- `CreateOrderFromInsuranceBooking`

If a new product is added, create product-specific adapters but reuse the same financial actions.

Refunds, voids, and cancellations must include both wallet reversal and ledger reversal where the original transaction affected accounting.

---

## 11. Refund, Void, Cancellation

Reverse flows must mirror issuance:

- Reverse merchant wallet if merchant paid.
- Reverse agency provider wallet if provider balance is restored.
- Update order item status.
- Record wallet metadata.
- Post reversal ledger entries.

Do not mark an item cancelled/refunded without financial reversal handling.

---

## 12. AI Checklist

Before implementing financial logic, verify:

- [ ] Does every wallet use bavix?
- [ ] Are no custom account/transaction tables extended?
- [ ] Is balance checked before external issuance?
- [ ] Is provider wallet checked before issuance?
- [ ] For merchant flow, are both merchant wallet and agency provider wallet checked?
- [ ] Are both wallet deductions logged with metadata?
- [ ] Is the order created in the correct tenant context?
- [ ] Are ledger entries posted/reversed?
- [ ] Are tests written for insufficient balance cases?
