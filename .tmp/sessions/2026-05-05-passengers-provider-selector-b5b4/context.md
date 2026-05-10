# Task Context: Passengers Provider Selector B5b-4

Session ID: 2026-05-05-passengers-provider-selector-b5b4
Created: 2026-05-05T09:31:45.957095+00:00
Status: in_progress

## Current Request
Apply ProviderSourceResolver to BookingController::passengers only, resolving provider from cached selected_offer provider_selector before provider_id fallback, preserving PassengerInfo props and ancillary catalog behavior.

## Context Files (Standards to Follow)
- /Users/abdullah/Herd/tams/AGENTS.md
- /Users/abdullah/Herd/tams/.tmp/sessions/2026-05-05-select-provider-selector-b5b3/context.md
- /Users/abdullah/Herd/tams/.tmp/sessions/2026-05-05-seatmap-provider-selector-b5b2/context.md
- /Users/abdullah/Herd/tams/.tmp/sessions/2026-05-05-provider-source-resolver/context.md
- /Users/abdullah/Herd/tams/.agents/skills/agency_network_and_merchant_model/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/booking_ux_patterns/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/laravel-best-practices/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/pest-testing/SKILL.md

## Reference Files (Source Material to Look At)
- app/Http/Controllers/Tenant/BookingController.php
- app/Services/AgencyNetwork/ProviderSourceResolver.php
- tests/Feature/Tenant/FlightBookingOrderFlowTest.php
- resources/js/Pages/Tenant/Bookings/PassengerInfo.jsx

## External Docs Fetched
Laravel Boost search-docs was used for cache/session testing and Inertia prop testing.

## Components
- BookingController::passengers provider lookup
- Focused Inertia response test for selector/fallback behavior

## Constraints
- Only passengers endpoint in this step.
- Preserve `provider_id` prop for existing frontend compatibility.
- Preserve provider_id fallback when selector is invalid/missing.
- Do not update store/issuance yet.
- Stop and report before auto-fixing failures.

## Exit Criteria
- [ ] passengers resolves provider using cached provider_selector when valid.
- [ ] passengers falls back to cached provider_id when selector is invalid/missing.
- [ ] PassengerInfo Inertia props remain compatible.
- [ ] Pint and focused Pest tests pass.
