# Task: Videcom Client and Base Airline Handler

**Priority:** High
**Assigned To:** AI Assistant
**Status:** Pending
**Dependencies:** [02_provider_core.md](file:///.trae/tasks/02_provider_core.md)

## Description
Implement the low-level `VidecomClient` for communication with the Videcom system and the `BaseVidecomAirline` abstract class. This task focuses on the plumbing required to send commands and receive raw responses, which will be extended by specific airline handlers.

**Note:** The implementation should account for both terminal-based command execution (using authentication snippets to be provided) and potential web scraping from the Expert Logon panel (`https://customer3.videcom.com/Medsky/VARS/Agent/login.aspx`).

## Definition of Done
- [ ] `VidecomClient` implemented with connection handling and command execution.
- [ ] Integration with the Expert Logon authentication flow.
- [ ] `BaseVidecomAirline` abstract class defined with abstract methods for command building and response parsing.
- [ ] `AirlineFactory` for Videcom to resolve specific airline implementations (e.g., QR, EK).
- [ ] Error handling for Videcom connection failures and invalid commands.

## Tests
- [ ] Unit tests for `VidecomClient` (mocked socket/HTTP communication).
- [ ] Unit tests for `AirlineFactory` resolution.
- [ ] Integration test verifying that `BaseVidecomAirline` methods are correctly called by the main provider.
