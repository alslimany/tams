---
name: default-agency-and-merchant-model
description: "Default agency (master agency) model for forcing tenant agencies to use default airline providers. For future merchant networks, use agency-network-and-merchant-model."
license: MIT
metadata:
  author: booknow
---

# Default Agency Model

## Related Skills

- Read `financial-and-wallet-system` for wallet, provider balance, order, and ledger rules.
- Read `agency-network-and-merchant-model` for future merchant network membership, invitation, provider allocation, and dual-wallet issuance rules.

This skill covers the current default agency/master agency behavior only.

## Current Implementation (Default Agency)
- Central admin can mark one tenant as `is_default_agency = true`.
- For any other tenant, admin can set:
  - `can_use_own_airline_credentials` (bool)
  - `force_use_default_agency` (bool)
  - `master_commission_percent` (decimal)
- If forced or not allowed own credentials, the agency uses the default agency's airline providers for bookings.
- Wallet deduction still happens in the buying agency's own wallet (tenant DB).
- Commission owed to default agency is tracked in `order_item.agent_commission` as a liability.
- Settlement is manual via central admin reports.

## Provider Resolution Rule

Default agency providers may belong to a different tenant database. Do not validate selected provider IDs with a local `exists:tenant_providers,id` rule when default agency providers are possible.

Use resolver-based lookup instead:

- Local tenant provider.
- Default agency provider.
- Future agency-network allocated provider.

Carry source metadata into selected offer cache, order item details, wallet metadata, and ledger context.

## Future: Agency Network & Merchant Model

The merchant network model is documented in `agency-network-and-merchant-model`.

Key decisions:

- Do not add `tenant_type`; future subscription packages/plans provide capabilities.
- Merchants join agency networks with invitation tokens.
- `network_memberships` and `provider_allocations` live in the central database.
- Merchant issuance validates both merchant wallet and agency provider wallet before external API calls.
- Agency provider credentials are never copied into merchant tenant databases.

## Transition Plan (for later)
- `network_memberships` and `provider_allocations` tables.
- Extend provider resolver to return agency-network allocated providers.
- Modify financial actions to support merchant wallet plus agency provider wallet validation/deduction.
- API endpoints for agency to manage network, merchant to request join.

## Current Restrictions (to keep in mind)
- No cross‑tenant wallet transfers yet.
- Wallets are per tenant database.
- Default agency feature is active; network model is design only.
