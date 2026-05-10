# Task Context: Provider Source Resolver B5a

Session ID: 2026-05-05-provider-source-resolver
Created: 2026-05-04T23:42:33.583671+00:00
Status: in_progress

## Current Request
Continue the agency-network provider allocation work by implementing the next safe step: B5a provider selector/source resolver service and tests only, without changing BookingController behavior yet.

## Context Files (Standards to Follow)
- /Users/abdullah/Herd/tams/AGENTS.md
- /Users/abdullah/Herd/tams/.agents/skills/agency_network_and_merchant_model/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/default_agency_and_merchant_model/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/financial_and_wallet_system/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/financial_process/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/laravel-best-practices/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/pest-testing/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/booking_ux_patterns/SKILL.md

## Reference Files (Source Material to Look At)
- app/Services/Airline/AgencyProviderResolver.php
- app/Services/AgencyNetwork/MerchantProviderAllocationResolver.php
- app/Services/AgencyNetwork/ProviderSourceSelector.php
- app/Models/ProviderAllocation.php
- app/Models/NetworkMembership.php
- app/Models/Tenant/TenantProvider.php
- tests/Feature/ProviderAllocationNetworkTest.php
- app/Http/Controllers/Tenant/BookingController.php

## External Docs Fetched
Laravel Boost search-docs was used for Laravel 12 service container/dependency injection, testing, Eloquent relationships, and database testing patterns.

## Components
- ProviderSourceResolver service
- Focused Pest coverage for own, default agency, and agency network selectors

## Constraints
- Do not modify BookingController behavior in this step.
- Do not copy provider credentials into merchant tenant database.
- Do not add tenant_type.
- Central provider_allocations and network_memberships remain source of truth.
- Keep default-agency fallback support and deprecation metadata.
- Use resolver-based provider lookup; do not local exists-validate cross-tenant providers.
- Every change must be tested.

## Exit Criteria
- [ ] Service resolves own provider selectors from current tenant.
- [ ] Service resolves default agency provider selectors from selected source tenant and includes deprecation metadata.
- [ ] Service resolves agency network allocation selectors only for active allocation and active membership.
- [ ] Invalid/inactive selectors return null provider safely.
- [ ] Focused tests pass.
- [ ] Dirty PHP files formatted with Pint.
