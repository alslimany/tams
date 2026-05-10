---
name: agency-network-and-merchant-model
description: "Canonical future model for agency networks and merchant tenants in TAMS. Defines invitation-token membership, central provider allocations, merchant access to agency provider APIs, and dual-wallet issuance validation."
license: MIT
metadata:
  author: booknow
---

# Agency Network & Merchant Model

## Mandatory Rule

Do not implement merchant behavior as an ad-hoc exception. All merchant access to agency APIs must go through the agency network model described here.

---

## 1. Tenant Capability Model

Do **not** add a `tenant_type` column.

Tenant capabilities will be derived from the subscription package/plan in the future.

Examples of future capabilities:

- Can configure providers.
- Can create an agency network.
- Can join networks.
- Can issue through allocated providers.

Until subscription plans are implemented, use explicit settings/permissions, not a hard-coded `tenant_type`.

---

## 2. Definitions

### Agency Tenant

An agency tenant can configure tenant providers:

- Airlines
- Hotels
- Insurance
- eSIM
- Other API integrations

Each configured provider must have its own bavix wallet.

### Merchant Tenant

A merchant tenant does not configure providers directly. It joins one or more agency networks and uses whitelisted agency providers.

### Agency Network

A space controlled by an agency tenant. The agency invites merchants and grants access to selected providers.

---

## 3. Central Database Ownership

Network data belongs in the central database.

Required future tables:

### `network_memberships`

Stores agency ↔ merchant relationship.

Suggested columns:

- `id`
- `agency_tenant_id`
- `merchant_tenant_id`
- `invitation_token`
- `status` (`pending`, `active`, `suspended`, `revoked`)
- `accepted_at`
- `created_by`
- timestamps

### `provider_allocations`

Stores provider whitelist per membership.

Suggested columns:

- `id`
- `network_membership_id`
- `agency_tenant_id`
- `merchant_tenant_id`
- `provider_type` (`airline`, `insurance`, `hotel`, `sim`)
- `provider_id`
- `provider_table` or morph fields if needed
- `is_active`
- `limits` JSON nullable
- timestamps

Do not duplicate allocations into the merchant tenant database unless a later sync/cache feature is explicitly designed.

---

## 4. Invitation Token Flow

Agency invites merchant by generating an invitation token.

Flow:

```text
Agency opens Network Settings
→ enters merchant identifier/contact
→ selects providers to whitelist
→ generates invitation token
→ merchant enters/accepts token in merchant settings
→ membership becomes active
```

Rules:

- Use invitation token/code, not direct tenant ID exposure.
- Token must be unique, expirable, and revocable.
- Merchant can join multiple agency networks.
- Agency can revoke or suspend merchant access.
- Provider allocations can be changed without deleting membership.

---

## 5. Provider Allocation Rules

A merchant can only use agency providers explicitly allocated to its active membership.

Example:

```text
Merchant M joins Agency A network:
- 5 airline providers allowed

Merchant M joins Agency B network:
- 2 airline providers allowed
- 1 hotel provider allowed
- 1 insurance provider allowed
```

When searching/offering products, the system must merge eligible providers from all active memberships while preserving source agency context.

---

## 6. Provider Resolution

`AgencyProviderResolver` must eventually support three sources:

1. Own tenant providers.
2. Default agency providers.
3. Agency network allocated providers.

For merchant context:

```text
Current tenant
→ active network memberships
→ active provider allocations
→ switch into agency tenant context
→ load provider credentials
→ return provider with source context
```

Returned provider metadata must include:

- `source_type`: `own`, `default_agency`, `agency_network`
- `source_agency_tenant_id`
- `merchant_tenant_id`
- `network_membership_id`
- `provider_allocation_id`

This metadata must be carried into offer payloads, selected offer cache, order item details, and wallet transaction metadata.

---

## 7. Merchant Issuance Financial Rule

Merchant issuance through an agency provider must use the dual-wallet flow from `financial-and-wallet-system`.

Required sequence:

```text
Validate merchant wallet balance
→ Validate agency provider wallet balance
→ Issue via agency provider API credentials
→ Deduct merchant wallet
→ Deduct agency provider wallet
→ Create order in merchant tenant DB
→ Log source agency/provider metadata
→ Post ledger/settlement records
```

Do not issue if either balance is insufficient.

---

## 8. Search and Offer Rules

Merchant search results must show offers from all allowed provider allocations.

Rules:

- Do not expose raw agency credentials to merchant.
- Merchant sees provider/airline/insurer name and offer details.
- Offer payload must include hidden source metadata for backend processing.
- Offer selection must cache source metadata.

---

## 9. Agency Network UI

Agency UI should provide:

- Network dashboard.
- Merchant invitations.
- Active merchants list.
- Provider whitelist management.
- Per-merchant provider access toggles.
- Merchant usage / sales summary.
- Revoke/suspend controls.

---

## 10. Merchant Network UI

Merchant UI should provide:

- Join network by invitation token.
- List joined agency networks.
- List allowed providers per network.
- Available products/integrations.
- Network status.

---

## 11. What Not To Do

- Do not add `tenant_type`.
- Do not copy agency provider credentials into merchant tenant DB.
- Do not store network memberships in tenant DB.
- Do not let merchant use providers without central allocation.
- Do not issue before checking both wallets.
- Do not create custom wallet tables.

---

## 12. AI Checklist

Before implementing merchant network logic, verify:

- [ ] Is membership stored centrally?
- [ ] Is provider allocation stored centrally?
- [ ] Is invitation token used?
- [ ] Is merchant allowed to join multiple networks?
- [ ] Does resolver include source metadata?
- [ ] Are both merchant wallet and agency provider wallet validated?
- [ ] Are both wallet deductions logged?
- [ ] Does order stay in merchant tenant context?
- [ ] Are agency credentials never copied to merchant DB?
