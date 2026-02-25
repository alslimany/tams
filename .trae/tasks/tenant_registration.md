# Task: Tenant Registration and Layout Implementation

**Priority:** High
**Assigned To:** AI Assistant
**Status:** In Progress

## Description
Implement the tenant (agency) registration flow and the dual-layout system (Landlord for central app, Tenant for agency-specific app). This includes creating the registration form, handling tenant/domain creation, and setting up the visual structure for both contexts.

## Definition of Done
- [ ] Central landing page shows a "Register Agency" button.
- [ ] Registration form collects: Company Name, Email, Subdomain, and Password.
- [ ] Successful registration creates:
    - A new `Tenant` record.
    - A `Domain` record for the chosen subdomain.
    - A `User` (Owner) inside the tenant's database.
- [ ] Landlord Layout (`resources/views/layouts/landlord.blade.php`) implemented for central pages.
- [ ] Tenant Layout (`resources/views/layouts/tenant.blade.php`) implemented for agency pages, integrated with Flux/Livewire.
- [ ] User is redirected to their new subdomain dashboard after registration.

## Tests
- [ ] Feature test for agency registration validation.
- [ ] Feature test for successful tenant and domain creation.
- [ ] Test for tenant isolation (user in Tenant A cannot log into Tenant B).
- [ ] UI verification of Landlord vs Tenant layouts.
