# Task Context: Provider Allocations Foundation

Session ID: 2026-05-04-provider-allocations
Created: 2026-05-04T15:50:00+03:00
Status: in_progress

## Current Request
Implement the next phase for centralized provider allocations based on the agreed design: central membership/allocation records store references only, while tenant provider configurations and encrypted credentials remain in the agency tenant database.

## Context Files (Standards to Follow)
- .agents/skills/agency_network_and_merchant_model/SKILL.md
- .agents/skills/default_agency_and_merchant_model/SKILL.md
- .agents/skills/financial_and_wallet_system/SKILL.md
- .agents/skills/booknow_architecture/SKILL.md
- .agents/skills/financial_process/SKILL.md
- .agents/skills/laravel-best-practices/SKILL.md
- /Users/abdullah/.opencode/context/core/standards/code-quality.md
- /Users/abdullah/.opencode/context/core/standards/test-coverage.md

## Reference Files (Source Material to Look At)
- app/Models/Tenant.php
- app/Models/DefaultAgencySetting.php
- app/Models/AgencyWalletTransaction.php
- app/Models/TenantProvider.php
- app/Models/Tenant/TenantInsuranceProvider.php
- database/migrations/2026_04_26_120100_add_default_agency_fields_to_tenants_table.php
- database/migrations/2026_04_26_130100_create_default_agency_settings_table.php

## External Docs Fetched
- Laravel 12 migration foreign keys and indexes.
- Laravel 12 model casts and enum casting.
- Laravel 12 validation/scoped uniqueness references.

## Components
- Central network membership migration and model.
- Central provider allocation migration and model.
- Application-level active logical-provider duplicate prevention.
- Feature tests for central storage, reference-only allocations, duplicate prevention, and removal workflow.

## Constraints
- Do not add `tenant_type`.
- Do not copy provider credentials/configuration into central `provider_allocations`.
- `provider_allocations` must reference tenant providers using agency tenant context plus provider model/ID.
- Merchant should have only one active allocation for a logical provider identity.
- Removal/unjoin workflow must preserve records and support agency approval later.
- No UI or issuance integration in this slice.

## Exit Criteria
- [ ] Central `network_memberships` and `provider_allocations` tables exist.
- [ ] Models use the central connection and typed casts/relationships.
- [ ] Active duplicate provider allocation prevention is covered by tests.
- [ ] Tests confirm provider credentials are not copied into central allocations.
- [ ] Focused tests and Pint pass.
