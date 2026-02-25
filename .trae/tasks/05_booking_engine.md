# Task: Booking Engine (Search, Fare, Book)

**Priority:** Medium
**Assigned To:** AI Assistant
**Status:** Pending
**Dependencies:** [04_airline_handlers_qr_ek.md](file:///.trae/tasks/04_airline_handlers_qr_ek.md)

## Description
Develop the integrated booking engine that allows agents to search for flights, get real-time fare quotes, and create PNRs (Passenger Name Records). This task links the UI to the provider backend.

**Note:** For availability and pricing updates, the system can leverage web scraping from the public Videcom panel (`https://customer3.videcom.com/Medsky/VARS/Public/CustomerPanels/requirementsBS.aspx`) to keep the database updated without excessive API calls.

## Definition of Done
- [ ] Unified search API endpoint that aggregates results from multiple providers/airlines.
- [ ] Availability scraping logic for Videcom-based airlines.
- [ ] Fare quote endpoint to verify price before booking.
- [ ] Booking endpoint to create a PNR in the airline system.
- [ ] `bookings` table migration and model to store local booking records.
- [ ] UI for search results and passenger information entry.

## Tests
- [ ] Feature test for the search flow (from request to DTO response).
- [ ] Feature test for the booking flow (PNR creation and local storage).
- [ ] Validation tests for passenger data.
- [ ] Error handling tests for failed bookings.
