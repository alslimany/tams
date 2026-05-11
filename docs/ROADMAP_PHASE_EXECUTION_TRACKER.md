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
| 3 | Order Page Redesign | Pending | Make order/item information clearer and easier to operate for all product types. |
| 4 | Notifications & WhatsApp | Pending | Add email and WhatsApp notifications for booking, issuance, cancellation, and files. |
| 5 | PDF / Printable Documents | Pending | Add printable ticket, hotel voucher, insurance policy, item PDF, and full order PDF. |
| 6 | Tenant External APIs | Pending | Expose secure tenant APIs for agency integrations and mobile apps. |

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

## Phase 3 — Order Page Redesign

### Goal

Make order pages easier to use and make item information clear by product type.

### Checklist

- [ ] Redesign order summary hierarchy.
- [ ] Separate item cards for flight, hotel, insurance.
- [ ] Improve action menus by status and role.
- [ ] Show correct provider/source context for agency and merchant orders.
- [ ] Prepare data layout for PDFs.

---

## Phase 4 — Notifications & WhatsApp

### Goal

Add transactional notifications through email and WhatsApp.

### Checklist

- [ ] Notification events for order created, ticket issued, hotel booked, policy issued.
- [ ] Cancellation requested/approved/finalized notifications.
- [ ] WhatsApp text message integration.
- [ ] WhatsApp file sending integration for generated PDFs.
- [ ] Queue notifications after DB commit.
- [ ] Notification preferences and logs.

---

## Phase 5 — PDF / Printable Documents

### Goal

Generate printable documents for order items and full orders.

### Checklist

- [ ] Flight ticket PDF.
- [ ] Hotel voucher / booking confirmation PDF.
- [ ] Insurance policy PDF integration or generated fallback.
- [ ] Full order PDF.
- [ ] Download, print, and WhatsApp-send actions.

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
