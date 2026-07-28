---
name: insurance-integration-albaraka
description: "Al Baraka Insurance REST API integration covering compulsory, travel, and orange insurance. Includes reference data, pricing, quote, and policy issuance."
license: MIT
metadata:
  author: booknow
---

# Al Baraka Insurance Integration (Swagger API)

## Base URL
`https://tameen.webapi.ly` (from config `services.albaraka.base_url`)

## Authentication
Bearer token stored in `tenant_insurance_providers.credentials` (JSON: `{token, base_url}`).

## Product Types
- **Compulsory** – vehicle obligatory insurance.
- **Travel** – passenger travel insurance.
- **Orange** – electronic unified Arab card (vehicle cross‑border).

## Common Reference Data Endpoints (GET)
- `/api/Compulsories/DurationsLookup` → insurance durations (Id, Value (days), Name)
- `/api/ReferenceData/GetDocumentTypes` → document types (Id, Name)
- `/api/ClientProfileVehicles/CarsLookup` → vehicle models (Id, Name)
- `/api/ClientProfileVehicles/ColorsLookup` → colors (Id, Name)
- `/api/ClientProfileVehicles/LicensingAuthoritiesLookup` → licensing authorities (Id, Name)

## Compulsory Flow
1. **Price**: `POST /api/Compulsories/CheckPolicyPrices` with `DocumentTypeId`, `InsuranceDurationId`, `NoPassengers`, `Payload` → returns `NetPremium`, `TotalPremium`.
2. **Create Client Profile**: `POST /api/ClientProfiles/Post` with `Name`, `Phone`, `Address`, `Email` → returns `Id`.
3. **Create Vehicle**: `POST /api/ClientProfileVehicles/Post` with `ClientProfileId`, `CarId`, `ColorId`, `ChassisNumber`, `MetalPlateNo`, `ManufactureYear`, `LicensingAuthorityId`, `NumberOfSeats`, `Payload` → returns `Id`.
4. **Create Policy**: `POST /api/Compulsories/Post` with `ClientProfileId`, `ClientProfileVehicleId`, `PolicyDateFrom`, `InsuranceDurationId`, `IsPolicyPaid=false` → returns `Id`.
5. **Fetch Policy Details** (optional): `GET /api/Compulsories/Get/{policyId}` for full data.

## Compulsory Policy Print (SPI Note)
- Endpoint: `GET /api/Compulsories/GetReportById`
- Query parameter used by the API docs: `EncryptedId`
- Practical behavior: the same request accepts either the encrypted id or the numeric policy id value.
- Recommended fallback strategy:
  1. Try with encrypted id first.
  2. If that call returns HTTP 500, retry using the numeric policy id in the same query parameter.
- Response is PDF binary content and should be rendered inline (new tab / popup) when possible.

## Travel Insurance Flow (similar pattern)
- Reference: zones (`/api/Travelers/ZonesLookup`), durations (`/api/Travelers/DurationsLookup`).
- Price: `POST /api/Travelers/CheckPolicyAgePrices` with `BirthDate`, `ZoneID`, `InsuranceDurationID`.
- Book: `POST /api/Travelers/Post` → creates policy.

## Orange Insurance Flow
- Reference: cars, countries, etc.
- Price: `POST /api/Oranges/CheckPolicyPrices`.
- Book: `POST /api/Oranges/Post`.

## Orange Policy Print (SPI Note)
- Endpoint: `GET /api/Oranges/GetReportById`
- Production probe (`LBY/6884971`, policy id `71223`) confirmed:
  - **Works:** `CardNumber={card}&Id={policy_id}` and `CardNumber={card}` alone
  - **Fails:** `Id` alone, `EncryptedId={id|card}`, `CardNumber={numeric id}` (JSON certificate error)
- Note: Al Baraka may still return HTTP 200 + `Content-Type: application/pdf` with a JSON error body.
  Always verify `%PDF` magic bytes before treating the response as a printable policy.
- App strategy:
  1. Prefer `CardNumber` + `Id` when both are stored on the order item.
  2. Fall back to `CardNumber` alone.
- Probe locally/production with:
  `php artisan insurance:probe-orange-report {tenant} --card=LBY/6884971 --id=71223`

## UI Patterns
- Search page: dropdowns for durations, document types, seats, payload → price → redirect to beneficiary form.
- Beneficiary form: personal details + vehicle details (dropdowns from reference endpoints).
- After booking: redirect to order page.

## Error Handling
- All API calls must be wrapped in try/catch. Log errors.
- Display user‑friendly messages (Arabic/English based on locale).

## Commission
- Stored per product type in `tenant_insurance_providers`.
- Commission amount = `net_premium * rate / 100`, stored in `order_item.commission_amount`.