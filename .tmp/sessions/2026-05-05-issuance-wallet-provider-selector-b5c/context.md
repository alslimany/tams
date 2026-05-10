# Task Context: Issuance Wallet Provider Selector B5c

Session ID: 2026-05-05-issuance-wallet-provider-selector-b5c
Created: 2026-05-05T09:50:15.886736+00:00
Status: in_progress

## Current Request
Finish the last phase up to wallet transactions: BookingController::store should use provider_selector for issuance, persist source metadata, and complete source provider wallet validation/deduction. Avoid new ledger semantics.

## Context Files (Standards to Follow)
- /Users/abdullah/Herd/tams/AGENTS.md
- /Users/abdullah/Herd/tams/.tmp/sessions/2026-05-05-passengers-provider-selector-b5b4/context.md
- /Users/abdullah/Herd/tams/.tmp/sessions/2026-05-05-provider-source-resolver/context.md
- /Users/abdullah/Herd/tams/.agents/skills/agency_network_and_merchant_model/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/financial_and_wallet_system/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/default_agency_and_merchant_model/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/financial_process/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/laravel-best-practices/SKILL.md
- /Users/abdullah/Herd/tams/.agents/skills/pest-testing/SKILL.md

## Reference Files (Source Material to Look At)
- app/Http/Controllers/Tenant/BookingController.php
- app/Services/AgencyNetwork/ProviderSourceResolver.php
- app/Actions/Finance/ProcessProviderWalletTransactions.php
- app/Actions/Finance/ProcessWalletTransactions.php
- app/Actions/Finance/ApplyFinancialSourceAndCommission.php
- app/Actions/Finance/DetermineFinancialSource.php
- tests/Feature/Tenant/FlightBookingOrderFlowTest.php
- tests/Feature/ProviderAllocationNetworkTest.php
- tests/Feature/Tenant/TicketIssueFinancialFlowTest.php

## External Docs Fetched
Laravel Boost search-docs was used for multiple database connections, database transactions, and HTTP test assertions.

## Components
- BookingController::store selector-aware provider lookup and item_details metadata
- ProcessProviderWalletTransactions source-provider wallet support
- Tests for source provider wallet deduction and insufficient source provider wallet blocking API call

## Constraints
- Finish wallet transactions only; do not introduce new ledger semantics.
- Do not copy agency provider credentials into merchant tenant DB.
- Validate source provider wallet before external API calls.
- Own provider wallet behavior must remain unchanged.
- Preserve provider_id fallback.
- Stop and report before auto-fixing validation failures.

## Exit Criteria
- [ ] store resolves API provider from provider_selector when valid.
- [ ] order item stores selected provider source metadata.
- [ ] source provider wallet is validated before API issuance.
- [ ] source provider wallet is deducted after successful issuance.
- [ ] insufficient source provider wallet prevents API booking call.
- [ ] ledger behavior is not expanded.
- [ ] Pint and focused Pest tests pass.
