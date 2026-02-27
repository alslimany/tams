# Videcom Command Reference

This document outlines the core VRS (Videcom Reservation System) commands for travel operations, based on the Videcom XML Service API.

## Core API Endpoint
`https://customer2.videcom.com/airlinename/vars/public/webservices/VRSXMLWebservice3.asmx?op=RunVRSCommand`

## Command Syntax Rules
- **Chaining**: Use `^` to link multiple commands (e.g., `*RLOC^5*Remark^E*R~x`).
- **XML Response**: Append `~x` to a command to request its response in XML format (e.g., `*RLOC~x`).
- **Transaction Handling**: Always end PNR-modifying transactions with `*R~x` to get a consistent XML response and verify the state.
- **Error Handling**: Any error in a chained command suspends execution and returns the error in plain text.

---

## 1. Availability & Pricing

### Availability (One-Way)
`A[Date][Origin][Dest][Options]`
Example: `A20NOVLOSABV[SalesCity=LOS,VARS=True,ClassBands=True,StartCity=LOS,SingleSeg=s,FGNoAv=True,qtyseats=1]`

### Availability (Return)
Chain two availability entries with `SingleSeg=r`.
Example:
`A20NOVLOSABV[...SingleSeg=r...]^A24NOVABVLOS[...SingleSeg=r...]`

### Price Itinerary (No Booking)
`i^[PassengerEntries]^[FlightEntries]^FG^FS1^*r~x`
Example (2 Adult, 1 Child, 1 Infant):
`i^-4Pax/A#/B#/C#.CH10/D#.IN06^0VL2100Y20NOVLOSABVQQ3^0VL2101Y24NOVABVLOSQQ3^FG^FS1^*r~x`
- `i`: Start fresh session.
- `QQ`: Book without holding space.
- `FG`: Fare Quote.
- `FS1`: Store Fare.

---

## 2. PNR Operations (Booking)

### Complete Booking & Issue Ticket
`[PassengerInfo]^[FlightSegments]^[Pricing]^[Payment]^[Ticketing]^*R~x`
Example:
`-1@test/testMr^9-1E*test@videcom.com^9-1M*+18989898^0VL2100Y20NOVLOSABVNN1^0VL2101Y24NOVABVLOSNN1^FG^FS1^MM^EZT*R^EZRE^*R~x`

- **Passenger Name**: `-1@[Surname]/[Firstname][Title]`
- **Email**: `9-1E*[Email]`
- **Phone**: `9-1M*[PhoneNumber]`
- **Passport**: `4-1FPSPT/[Number]/[Country]/[DOB]/[Surname]/[Firstname]/[Gender]`
- **DOB**: `3-1FDOB [Date]`
- **Gender**: `3-1FGNDR [Gender]`
- **Flight Booking (Hold Space)**: `0[AirlineCode][FltNo][Class][Date][Origin][Dest]NN[Qty]`
- **Payment (Cash)**: `MM`
- **Issue & Email Ticket**: `EZT*R^EZRE`

---

## 3. Post-Booking Operations

### Display PNR
`*[RLOC]~x`

### Add Remark to PNR
`*[RLOC]^5*[RemarkText]^E*R~x`

### Modify PNR (Cancel Segment)
*(Requires verification, common VRS command)*
`*[RLOC]^X[SegmentNumber]^E*R~x`

---

## 4. Required Commands for Implementation (To be confirmed)

The following operations are typically handled using these patterns in VRS:

- **Seat Selection**: Often `SM[SegmentNumber]` for seat map, and `ST[PaxNumber]/[SeatNumber]` for selection.
- **Refund**: `TR[TicketNumber]` or `TR[RLOC]` depending on the airline's rules.
- **Void**: `TV[TicketNumber]` (usually only valid on the same day as issuance).
- **Change**: `X[SegmentNumber]^A[NewDate]...^FG^FS1^E*R~x`.

---

## 5. Summary of Supported API Commands

| Command | Actions |
| :--- | :--- |
| `schedule` | `count`, `selectflt`, `delete`, `update`, `create` |
| `avcap` | `count`, `update`, `addnewclass` |
| `pnl` | `count`, `update` |
| `avs` | `count`, `update`, `send` |
| `seatplan` | `count`, `update` |
| `flighttime` | `count`, `update`, `cancel`, `delete` |
