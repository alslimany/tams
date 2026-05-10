# Task Context: Seatmap Provider Selector B5b-2

Session ID: 2026-05-05-seatmap-provider-selector-b5b2
Created: 2026-05-05T09:19:49.049683+00:00
Status: in_progress

## Current Request
Apply ProviderSourceResolver to BookingController::seatmap only, preserving provider_id fallback, and add focused Pest coverage.

## Context Files (Standards to Follow)
- /Users/abdullah/Herd/tams/AGENTS.md
- /Users/abdullah/Herd/tams/.tmp/sessions/2026-05-05-provider-source-resolver/context.md
- /Users/abdullah/Herd/tams/.tmp/sessions/2026-05-05-booking-provider-selector-b5b1/context.md
- /Users/abdullah/Herd/tams/.agents/skills/agency_network_and_merchant_model/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/booking_ux_patterns/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/laravel-best-practices/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/pest-testing/SKILL.md

## Reference Files (Source Material to Look At)
- app/Http/Controllers/Tenant/BookingController.php
- app/Services/AgencyNetwork/ProviderSourceResolver.php
- tests/Feature/Tenant/FlightBookingOrderFlowTest.php

## External Docs Fetched
Laravel Boost search-docs was used for nullable request validation, HTTP JSON tests, and controller dependency injection.

## Components
- BookingController::seatmap validation/resolution
- FlightBookingOrderFlowTest seatmap selector/fallback coverage

## Constraints
- Only seatmap endpoint in this step.
- Preserve provider_id fallback when selector is absent or invalid.
- Do not modify issuance/store behavior.
- Stop and report before auto-fixing failures.

## Exit Criteria
- [ ] seatmap accepts nullable provider_selector.
- [ ] seatmap prefers valid provider_selector.
- [ ] seatmap falls back to provider_id when selector is invalid/missing.
- [ ] Pint and focused Pest tests pass.
