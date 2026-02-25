# Task: User and Role Management (Tenant-Level)

**Priority:** High
**Assigned To:** AI Assistant
**Status:** Pending
**Dependencies:** [tenant_registration.md](file:///.trae/tasks/tenant_registration.md)

## Description
Implement the internal user management system for agencies. Each agency (tenant) should be able to create, update, and deactivate their own agents. This includes role-based access control (RBAC) to restrict certain actions (e.g., only managers can issue tickets).

## Definition of Done
- [ ] CRUD interface for users within the tenant dashboard.
- [ ] "Active/Inactive" toggle for users.
- [ ] Basic RBAC implementation (Agent, Manager, Admin).
- [ ] Permissions check on core actions (Searching, Booking, Ticketing).
- [ ] Activity tracking for each user (Last Login, Last Action).

## Tests
- [ ] Unit test for User model tenant scoping.
- [ ] Feature test for user creation and role assignment.
- [ ] Test that deactivated users cannot log in.
- [ ] Test that permissions are correctly enforced across different roles.
