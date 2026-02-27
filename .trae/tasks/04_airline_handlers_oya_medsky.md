# Task: Oya and Medsky Airline Handler Implementation

**Priority:** High
**Assigned To:** AI Assistant
**Status:** In Progress
**Dependencies:** [03_videcom_base.md](file:///.trae/tasks/03_videcom_base.md)

## Description
Implement the specific Videcom handlers for Oya Airline (YI) and Medsky Airline (BM). This involves implementing the airline-specific command formats and parsing their unique response patterns for search and fare quotes. A key focus is mapping the raw Videcom XML data to a unified system-wide Flight DTO.

## Implementation Details
- `OyaAirline` (YI) handler implementation.
- `MedskyAirline` (BM) handler implementation with multi-account support (LYD/EUR).
- Implementation of `VidecomResponseParser` to map XML results to `FlightOption` DTOs.
- Handling of account-specific airport restrictions.

## Definition of Done
- [x] `OyaAirline` class created and linked in `ProviderFactory`.
- [x] `MedskyAirline` class created and linked in `ProviderFactory`.
- [x] `VidecomResponseParser` implemented and used in `BaseVidecomAirline`.
- [x] Mapping of raw flight data to unified `FlightOption` DTOs.
- [ ] Verification of airport restrictions logic for Medsky accounts.

## Tests
- [ ] Unit tests for `OyaAirline` using raw response samples.
- [ ] Unit tests for `MedskyAirline` using raw response samples.
- [ ] Feature test for flight search through the `ProviderFactory` for both airlines.
