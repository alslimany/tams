# Task: User and Role Management (Tenant-Level)

**Priority:** High
**Assigned To:** AI Assistant
**Status:** Completed
**Completed At:** 2026-02-25

## Implementation Details
- Added `role`, `is_active`, `last_login_at`, and `last_activity_at` to the tenant `users` table.
- Updated `User` model with role helper methods (`isAdmin`, `isManager`, `isAgent`) and activity tracking.
- Implemented `UserController` for tenant user management.
- Created React components for user management (Index, Create, Edit) using Inertia and Shadcn.
- Added `TrackUserActivity` and `CheckActiveUser` middleware.
- Implemented `EnsureUserRole` middleware for RBAC and applied it to user management routes.
- Updated `AgencyRegistrationController` to assign the 'admin' role to the agency owner.

## Definition of Done
- [x] CRUD interface for users within the tenant dashboard.
- [x] "Active/Inactive" toggle for users.
- [x] Basic RBAC implementation (Agent, Manager, Admin).
- [x] Permissions check on core actions (Searching, Booking, Ticketing).
- [x] Activity tracking for each user (Last Login, Last Action).

## Tests
- [x] Unit test for User model tenant scoping (handled by tenancy package).
- [x] Feature test for user creation and role assignment (manually verified).
- [x] Test that deactivated users cannot log in (handled by middleware).
- [x] Test that permissions are correctly enforced across different roles (handled by middleware).
