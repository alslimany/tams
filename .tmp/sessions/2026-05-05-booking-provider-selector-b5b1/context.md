# Task Context: Booking Provider Selector B5b-1

Session ID: 2026-05-05-booking-provider-selector-b5b1
Created: 2026-05-04T23:54:23.527438+00:00
Status: in_progress

## Current Request
Wire ProviderSourceResolver into one low-risk BookingController provider resolution path while retaining provider_id fallback. Do not change final issuance/store behavior yet.

## Context Files (Standards to Follow)
- /Users/abdullah/Herd/tams/AGENTS.md
- /Users/abdullah/Herd/tams/.tmp/sessions/2026-05-05-provider-source-resolver/context.md
- /Users/abdullah/Herd/tams/.agents/skills/agency_network_and_merchant_model/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/default_agency_and_merchant_model/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/booking_ux_patterns/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/financial_and_wallet_system/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/laravel-best-practices/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/pest-testing/SKILL.md

## Reference Files (Source Material to Look At)
- app/Http/Controllers/Tenant/BookingController.php
- app/Services/AgencyNetwork/ProviderSourceResolver.php
- app/Services/AgencyNetwork/ProviderSourceSelector.php
- app/Services/Airline/AgencyProviderResolver.php
- tests/Feature/Tenant/FlightBookingOrderFlowTest.php
- tests/Feature/Tenant/GlobalRouteCacheSearchTest.php
- tests/Feature/Tenant/ReturnOptionsSkipsUnsupportedProvidersTest.php
- tests/Feature/ProviderAllocationNetworkTest.php

## External Docs Fetched
Laravel Boost search-docs was used for Laravel 12 controller dependency injection, request validation, testing, and service container patterns.

## Components
- Minimal BookingController provider selection helper or targeted endpoint change
- Focused test around provider_selector use with provider_id fallback retained

## Constraints
- One low-risk endpoint/path only.
- Preserve provider_id fallback.
- Do not touch final booking issuance/store unless inspection shows a safer target is impossible.
- Do not copy credentials.
- Do not validate cross-tenant providers with local exists rules.
- Stop and report before fixing any validation/test failure.

## Exit Criteria
- [ ] One selected BookingController path prefers provider_selector when valid.
- [ ] Existing provider_id fallback remains.
- [ ] Focused tests cover selector preference/fallback.
- [ ] Pint runs on dirty PHP files.
- [ ] Focused Pest tests pass.
