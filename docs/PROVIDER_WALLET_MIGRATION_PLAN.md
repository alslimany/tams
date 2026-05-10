# Provider Wallet Migration Plan

## Purpose

This plan migrates provider balance tracking from custom account/transaction tables to the canonical `bavix/laravel-wallet` package.

All wallets in TAMS must use `bavix/laravel-wallet`, including:

- Tenant/user wallets.
- Airline provider wallets.
- Insurance provider wallets.
- Future hotel/eSIM provider wallets.

---

## Current Legacy Structures

These tables/models currently act as custom wallet systems and should be deprecated:

### Airline

- `airline_accounts`
- `airline_transactions`
- `App\Models\Tenant\AirlineAccount`
- `App\Models\Tenant\AirlineTransaction`

### Insurance

- `tenant_insurance_provider_accounts`
- `tenant_insurance_provider_transactions`
- `App\Models\Tenant\TenantInsuranceProviderAccount`
- `App\Models\Tenant\TenantInsuranceProviderTransaction`

Do not extend these models with new behavior. Use them only as migration sources until removed.

---

## Target Design

### `TenantProvider`

Add `bavix/laravel-wallet` support:

```php
use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Traits\HasWallet;

class TenantProvider extends Model implements Wallet
{
    use HasWallet;
}
```

### `TenantInsuranceProvider`

Add `bavix/laravel-wallet` support:

```php
use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Traits\HasWallet;

class TenantInsuranceProvider extends Model implements Wallet
{
    use HasWallet;
}
```

### Transaction Metadata

All provider wallet transactions must include metadata:

```php
[
    'type' => 'provider_deposit|provider_issuance_cost|refund|void|cancellation',
    'provider_type' => 'airline|insurance|hotel|sim',
    'provider_id' => $provider->id,
    'order_id' => $orderId,
    'order_item_id' => $orderItemId,
    'provider_reference' => $providerReference,
    'reference_number' => $referenceNumber,
    'deposit_date' => $depositDate,
]
```

---

## Migration Phases

### Phase 1 — Add Wallet Support

1. Update `TenantProvider` to implement `Wallet` and use `HasWallet`.
2. Update `TenantInsuranceProvider` to implement `Wallet` and use `HasWallet`.
3. Confirm bavix wallet tables exist in tenant migrations.
4. Add tests proving providers can create wallets and deposit/withdraw.

### Phase 2 — Backfill Balances

Create an Artisan command or migration-safe backfill process:

- For every `AirlineAccount`, deposit the current balance into its related `TenantProvider` wallet.
- For every `TenantInsuranceProviderAccount`, deposit the current balance into its related `TenantInsuranceProvider` wallet.
- Use metadata:

```php
[
    'type' => 'legacy_balance_backfill',
    'legacy_table' => 'airline_accounts|tenant_insurance_provider_accounts',
    'legacy_id' => $legacyAccount->id,
]
```

Do not delete old rows during this phase.

### Phase 3 — Replace Deposit Logic

Replace:

- `AirlineAccount` balance updates.
- `AirlineTransaction::create()` calls.
- `TenantInsuranceProviderAccount` balance updates.
- `TenantInsuranceProviderTransaction::create()` calls.

With:

```php
$provider->depositFloat($amount, $metadata);
```

### Phase 4 — Replace Issuance Deductions

Before external issuance:

```php
if (! $provider->wallet->canWithdrawFloat($requiredAmount)) {
    throw new InsufficientWalletBalanceException(...);
}
```

After successful external issuance:

```php
$transaction = $provider->withdrawFloat($requiredAmount, $metadata);
```

Store `$transaction->uuid` on `order_items` or product details as the provider wallet transaction reference.

### Phase 5 — Merchant Dual-Wallet Support

For merchant issuance through agency provider:

1. Validate merchant wallet.
2. Validate agency provider wallet.
3. Issue through agency provider API.
4. Deduct merchant wallet.
5. Deduct agency provider wallet.
6. Store both transaction UUIDs in `order_items.item_details`.

### Phase 6 — Tests

Add/update tests for:

- Provider deposit creates bavix transaction.
- Provider insufficient balance blocks issuance before API call.
- Agency issuance deducts provider wallet.
- Insurance issuance deducts insurance provider wallet.
- Merchant issuance deducts both merchant and agency provider wallets.
- Refund/void/cancellation reverses provider wallet if needed.

### Phase 7 — Deprecate Legacy Models

Once all code paths use bavix:

1. Mark legacy models deprecated with PHPDoc.
2. Remove UI references to legacy accounts.
3. Keep tables read-only for one release.
4. Drop tables only after production data validation.

---

## Code Search Targets

Before migration, search and replace usages of:

- `AirlineAccount`
- `AirlineTransaction`
- `TenantInsuranceProviderAccount`
- `TenantInsuranceProviderTransaction`
- `airline_account_id`
- `airline_transaction_id`
- `tenant_insurance_provider_account_id`
- `tenant_insurance_provider_transaction_id`

---

## Risks

- Double counting if backfill runs more than once.
- Existing reports may read legacy transaction tables.
- Order items may reference legacy transaction IDs.
- Tests may assume custom balance columns.

Mitigation:

- Make backfill idempotent by storing `legacy_balance_backfill` metadata and checking before deposit.
- Keep old references during transition.
- Add new wallet transaction UUID fields or store references in `item_details` before dropping legacy columns.

---

## Completion Criteria

Migration is complete when:

- [ ] `TenantProvider` uses bavix wallet.
- [ ] `TenantInsuranceProvider` uses bavix wallet.
- [ ] Provider deposits use bavix wallet transactions.
- [ ] Provider issuance deductions use bavix wallet transactions.
- [ ] No production code writes to legacy account/transaction tables.
- [ ] Tests cover insufficient provider balance.
- [ ] Merchant dual-wallet flow is implemented and tested.
- [ ] Legacy tables are marked deprecated or removed after validation.
