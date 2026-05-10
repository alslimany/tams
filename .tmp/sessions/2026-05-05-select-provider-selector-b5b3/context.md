# Task Context: Select Provider Selector B5b-3

Session ID: 2026-05-05-select-provider-selector-b5b3
Created: 2026-05-05T09:23:49.483869+00:00
Status: in_progress

## Current Request
Apply ProviderSourceResolver to BookingController::select only, preserving provider_id fallback, selected offer source metadata, and ancillary catalog behavior.

## Context Files (Standards to Follow)
- /Users/abdullah/Herd/tams/AGENTS.md
- /Users/abdullah/.opencode/context/core/standards/code-quality.md
- /Users/abdullah/.opencode/context/core/standards/test-coverage.md
- /Users/abdullah/.opencode/context/core/standards/security-patterns.md
- /Users/abdullah/Herd/tams/.tmp/sessions/2026-05-05-provider-source-resolver/context.md
- /Users/abdullah/Herd/tams/.tmp/sessions/2026-05-05-booking-provider-selector-b5b1/context.md
- /Users/abdullah/Herd/tams/.tmp/sessions/2026-05-05-seatmap-provider-selector-b5b2/context.md
- /Users/abdullah/Herd/tams/.agents/skills/agency_network_and_merchant_model/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/booking_ux_patterns/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/laravel-best-practices/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/pest-testing/SKILL.md

## Reference Files (Source Material to Look At)
- app/Http/Controllers/Tenant/BookingController.php
- app/Services/AgencyNetwork/ProviderSourceResolver.php
- tests/Feature/Tenant/FlightBookingOrderFlowTest.php
- tests/Feature/ProviderAllocationNetworkTest.php

## External Docs Fetched
Laravel Boost search-docs was used for nullable request validation, HTTP redirect/cache tests, and controller dependency injection.

## Components
- BookingController::select provider lookup
- selected offer cache test for selector preference and fallback

## Constraints
- Only select endpoint in this step.
- Preserve selected offer metadata exactly.
- Preserve provider_id fallback when selector is invalid/missing.
- Do not update passengers/store yet.
- Stop and report before auto-fixing failures.

## Exit Criteria
- [ ] select prefers valid provider_selector for provider factory/catalog resolution.
- [ ] select falls back to provider_id when selector is invalid/missing.
- [ ] selected offer cache still stores source metadata.
- [ ] Pint and focused Pest tests pass.
