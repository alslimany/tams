# Task: Subscription Plans and Smart Billing

**Priority:** Medium
**Assigned To:** AI Assistant
**Status:** Pending
**Dependencies:** [01_user_management.md](file:///.trae/tasks/01_user_management.md)

## Description
Implement the SaaS billing model. This includes defining subscription plans, tracking active users for the billing period, and calculating the monthly invoice based on the "base seats + extra active seats" logic.

## Definition of Done
- [ ] `subscription_plans` table and CRUD for landlord.
- [ ] Smart billing logic to calculate active users from logs.
- [ ] Invoice generation system.
- [ ] Agency "Seasonal Freeze" feature (60 days max).
- [ ] UI for agencies to view their subscription and invoices.

## Tests
- [ ] Unit test for active user calculation logic.
- [ ] Feature test for subscription plan switching.
- [ ] Test for the "Freeze" account functionality and its impact on billing.
- [ ] Test for invoice amount calculation including extra seats.
