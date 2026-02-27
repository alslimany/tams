# Task: Videcom Client and Base Airline Handler

**Priority:** High
**Assigned To:** AI Assistant
**Status:** Completed
**Completed At:** 2026-02-26

## Implementation Details
- Implemented `VidecomClient` to handle low-level SOAP communication with the Videcom XML API.
- Created `BaseVidecomAirline` abstract class implementing `AirlineProviderInterface` with default Videcom command logic.
- Implemented `OyaAirline` as the first specific airline handler extending `BaseVidecomAirline`.
- Integrated `OyaAirline` into the `ProviderFactory`.

## Definition of Done
- [x] `VidecomClient` implemented with connection handling and command execution.
- [x] Integration with the Expert Logon authentication flow (handled via token in client).
- [x] `BaseVidecomAirline` abstract class defined with abstract methods for command building and response parsing.
- [x] `AirlineFactory` for Videcom to resolve specific airline implementations (e.g., OYa).
- [x] Error handling for Videcom connection failures and invalid commands.

## Tests
- [ ] Unit tests for `VidecomClient` (mocked socket/HTTP communication).
- [ ] Unit tests for `AirlineFactory` resolution.
- [ ] Integration test verifying that `BaseVidecomAirline` methods are correctly called by the main provider.
