---
name: default-agency-and-merchant-model
description: "Default agency (master agency) model for forcing tenant agencies to use default airline providers, and future merchant network model with agency/merchant relationships."
license: MIT
metadata:
  author: booknow
---

# Default Agency & Merchant Model (Planned)

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

## Future: Agency Network & Merchant Model (Not Yet Implemented)
- **Agency**: Can host its own providers and invite merchants to join its network.
- **Merchant**: Small agency without own providers. Can join multiple Agency networks, selecting which providers to use from each.
- Wallets will move to central DB to enable cross‑tenant transfers.
- Orders will be stored in merchant's tenant DB, with references to the supplying agency.
- Commission splitting between Agency and Merchant will be handled centrally.

## Transition Plan (for later)
- Central wallet tables (`entities`, `wallets`, `transactions`).
- `network_memberships` and `provider_allocations` tables.
- Modify financial actions to use central wallets.
- API endpoints for agency to manage network, merchant to request join.

## Current Restrictions (to keep in mind)
- No cross‑tenant wallet transfers yet.
- Wallets are per tenant database.
- Default agency feature is active; network model is design only.