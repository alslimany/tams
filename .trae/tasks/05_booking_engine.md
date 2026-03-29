# Task: Booking Engine (Search, Fare, Book)

**Priority:** High
**Assigned To:** AI Assistant
**Status:** In Progress
**Dependencies:** [04_airline_handlers_oya_medsky.md](file:///.trae/tasks/04_airline_handlers_oya_medsky.md)

## Description
Develop the integrated booking engine using a dual-path approach:
1. **Web Crawler Path**: Scraping the public `requirementsBS.aspx` page for fast search and pricing data.
2. **Command Path**: Using authenticated VRS commands for precise availability, final pricing, PNR creation, and ticket issuance.

## Subtasks
### Path 1: Web Crawler (Discovery & Discovery)
- [ ] Implement `VidecomScraper` service to fetch and parse `requirementsBS.aspx`.
- [ ] Handle ASP.NET ViewState and postback requirements for the search form.
- [ ] Map scraped results to `FlightOption` DTOs.

### Path 2: Command Engine (Transactional)
- [ ] Refine `BaseVidecomAirline` methods for `searchAvailability` and `getPricing`.
- [ ] Implement PNR creation logic (`createBooking`) using the `-1@...^0VL...` command chain.
- [ ] Implement issuance logic (`issueTicket`) with `EZT*R^EZRE`.

### Path 3: Aggregation & UI
- [ ] Create `FlightSearchService` to merge results from both paths.
- [ ] Implement `bookings` table migration and model.
- [ ] Build the Search & Booking frontend pages (Inertia/React).

## Definition of Done
- [ ] Search returns aggregated `FlightOption` DTOs.
- [ ] Fare verification works before booking.
- [ ] Successful PNR creation and ticket issuance in Videcom.
- [ ] Local booking records stored in tenant database.

## Tests
- [ ] Mocked integration tests for `VidecomScraper`.
- [ ] Feature tests for the authenticated command flow.
- [ ] Validation of PNR parsing logic.
