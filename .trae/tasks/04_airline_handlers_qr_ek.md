# Task: Qatar Airways and Emirates Handler Implementation

**Priority:** High
**Assigned To:** AI Assistant
**Status:** Pending
**Dependencies:** [03_videcom_base.md](file:///.trae/tasks/03_videcom_base.md)

## Description
Implement the specific Videcom handlers for Qatar Airways (QR) and Emirates (EK). This involves implementing the airline-specific command formats and parsing their unique response patterns for search and fare quotes.

## Definition of Done
- [ ] `QatarVidecomAirline` implemented with QR-specific command building and response parsing.
- [ ] `EmiratesVidecomAirline` implemented with EK-specific command building and response parsing.
- [ ] Mapping of raw flight data to unified `FlightOption` DTOs.
- [ ] Implementation of `supports()` method for airline-specific features.

## Tests
- [ ] Unit tests for `QatarVidecomAirline` using raw response samples from the documentation.
- [ ] Unit tests for `EmiratesVidecomAirline` using raw response samples.
- [ ] Feature test for flight search through the `ProviderFactory` for both airlines.
