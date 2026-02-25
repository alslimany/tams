# Task: Ticketing Management (Issue, Void, Refund)

**Priority:** Medium
**Assigned To:** AI Assistant
**Status:** Pending
**Dependencies:** [05_booking_engine.md](file:///.trae/tasks/05_booking_engine.md)

## Description
Implement the ticketing lifecycle, allowing agencies to issue e-tickets from existing bookings, void tickets within the allowed window, and process refunds.

## Definition of Done
- [ ] Ticket issuance method in providers and API endpoint.
- [ ] Void and Refund methods implemented for Videcom handlers.
- [ ] `tickets` table migration and model.
- [ ] UI for viewing ticket details and performing ticket actions.
- [ ] Integration with the booking list to show ticket status.

## Tests
- [ ] Feature test for successful ticket issuance.
- [ ] Feature test for voiding a ticket.
- [ ] Feature test for refund processing.
- [ ] Permission tests (e.g., only managers can void/refund).
