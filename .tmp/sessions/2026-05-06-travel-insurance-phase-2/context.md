# Task Context: Travel Insurance Phase 2

Session ID: 2026-05-06-travel-insurance-phase-2
Created: 2026-05-06T00:00:00+02:00
Status: in_progress

## Current Request
Continue Phase 2: finish Travel insurance issuance/report/cancellation/wallet behavior using Al Baraka API, after prior discovery indicated the final Travel issue request may not be reliably covered or sent.

## Context Files (Standards to Follow)
- /Users/abdullah/.opencode/context/core/standards/code-quality.md
- /Users/abdullah/Herd/tams/.agents/skills/insurance_integration_albaraka/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/financial_and_wallet_system/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/booking_ux_patterns/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/laravel-best-practices/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/pest-testing/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/financial_process/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/albaraka_compulsory_insurance_flow/SKILL.md

## Reference Files (Source Material to Look At)
- app/Http/Controllers/Tenant/TravelInsuranceController.php
- app/Http/Controllers/Tenant/CompulsoryInsuranceController.php
- app/Http/Controllers/Tenant/InsurancePolicyController.php
- app/Services/Insurance/Providers/AlBarakaProvider.php
- app/Services/Insurance/InsuranceProviderManager.php
- app/Actions/Finance/CreateOrderFromInsuranceBooking.php
- app/Actions/Finance/ProcessInsuranceProviderWalletTransactions.php
- app/Actions/Finance/FinalizeInsuranceCancellation.php
- app/Actions/Orders/SyncInsuranceCancellationStatus.php
- app/Http/Requests/Tenant/Insurance/TravelIssueRequest.php
- resources/js/Pages/Tenant/Insurance/TravelBeneficiary.jsx
- tests/Feature/Tenant/TravelInsuranceFlowTest.php
- tests/Feature/Tenant/InsuranceCancellationFlowTest.php
- routes/tenant.php

## External Docs Fetched
- Laravel 12 HTTP client/testing docs via Boost search-docs:
  - Http::fake / Http::assertSent / Http::assertNotSent / Http::preventStrayRequests
  - HTTP response error handling and throw semantics
- Al Baraka Swagger JSON was previously fetched from https://tameen.webapi.ly/swagger/v1/swagger.json during discovery.

## Components
- Travel insurance issuance controller and Al Baraka payload/response handling.
- Travel order creation and provider wallet deduction via bavix wallet.
- Travel report PDF endpoint support.
- Travel cancellation request/status/finalization coverage.
- Focused Pest tests and Pint formatting.

## Constraints
- Use bavix/laravel-wallet only; do not extend legacy provider account/transaction tables.
- Validate insurance provider wallet before any `/api/Travelers/Post` issuance call.
- Create orders only after successful Travel policy issue.
- Commission for Al Baraka Travel is calculated from net premium, not as provider discount.
- Do not expand ledger semantics beyond existing flow.
- Keep Travel in the shared insurance search flow.
- Stop and report before auto-fixing any validation/test failure.

## Exit Criteria
- [ ] Travel issue tests prove `/api/Travelers/Post` is called after client/pax setup.
- [ ] Insufficient provider wallet test proves issuance endpoints are not called.
- [ ] Successful Travel issue creates issued order/items and deducts provider wallet.
- [ ] Travel commission is calculated from net premium.
- [ ] Travel report route fetches `/api/Travelers/GetReportById` and returns PDF.
- [ ] Travel cancellation request/status/finalization behavior is covered or confirmed via existing product-agnostic code.
- [ ] `vendor/bin/pint --dirty --format agent` passes.
- [ ] Focused Travel/cancellation tests pass.
