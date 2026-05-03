---
name: albaraka-compulsory-insurance-flow
description: "Definitive API flow for issuing compulsory vehicle insurance via Al Baraka API, based on real production traces. Includes duplicate client profile avoidance, vehicle creation fields, policy creation, and fetching full policy details. Required for any AI implementing compulsory insurance."
license: MIT
metadata:
  author: booknow
  source: swagger + telescope traces
---

---

## 1. Overview

Compulsory insurance (obligatory vehicle liability) requires three main steps:

1. **Obtain or create a Client Profile** (beneficiary/vehicle owner).  
   - **Always check by phone first** to avoid "duplicate phone" error (`400`).
2. **Create a Client Profile Vehicle** under that profile.
3. **Create the Compulsory Policy** linking profile and vehicle.
4. **(Optional but recommended) Fetch full policy details** to get `NetPremium`, taxes, etc.

No single “issue” endpoint exists; you must perform these sequential calls.

---

## 2. Step-by-Step API Calls

### Step 0: Reference Data (Pre‑requisite)

Before the user sees the beneficiary form, you must fetch reference IDs from these endpoints:

| Purpose | Endpoint | Returns |
|---------|----------|---------|
| Vehicle types (CarId) | `GET /api/ClientProfileVehicles/CarsLookup` | `{ Id, Name }` |
| Colors (ColorID) | `GET /api/ClientProfileVehicles/ColorsLookup` | `{ Id, Name }` |
| Licensing authorities | `GET /api/ClientProfileVehicles/LicensingAuthoritiesLookup` | `{ Id, Name }` |
| Insurance durations | `GET /api/Compulsories/DurationsLookup` | `{ Id, Value (days), Name }` |
| Document types (optional) | `GET /api/ReferenceData/GetDocumentTypes` | `{ Id, Name }` |

Store these IDs to use in requests.

---

### Step 1: Check for Existing Client Profile by Phone

**Endpoint:** `GET /api/ClientProfiles/GetByPhone`

**Query Parameter:**
```
Phone=0911388788
```

**Response (if found):**
```json
{
  "Code": 200,
  "Statues": true,
  "Messages": "تم ارسال البيانات",
  "data": {
    "Id": 42018,
    "Code": 30700,
    "Name": "عبدالله إشتيوي",
    "Phone": "0911388788",
    "Address": "طرابلس - ليبيا",
    "Email": "i.abdullah@median.ly"
  }
}
```

**Action:**  
- If found, use this `Id` as `ClientProfileId` and **skip Step 2**.
- If not found, proceed to Step 2.

---

### Step 2: Create Client Profile (Only if Not Found)

**Endpoint:** `POST /api/ClientProfiles/Post`

**Request Body:**
```json
{
  "Name": "ABDULLAH",
  "Phone": "911388788",
  "Address": "Libya",
  "Email": "alslimany@gmail.com"
}
```

**Response (Success):**
```json
{
  "Code": 200,
  "Statues": true,
  "Messages": "OK",
  "Id": 42019,
  ...
}
```

**Response (Error – duplicate phone):**
```json
{
  "Code": 500,
  "Statues": false,
  "Messages": "هذا الهاتف مسجل بالفعل"
}
```
→ This error should never happen if you check by phone first. If it does, fall back to Step 1.

---

### Step 3: Create Client Profile Vehicle

**Endpoint:** `POST /api/ClientProfileVehicles/Post`

**Required Fields (from real trace):**

| Field | Type | Example | Notes |
|-------|------|---------|-------|
| `Name` | string | `"ABDULLAH"` | Owner name (same as in client profile) |
| `Address` | string | `"Libya"` | Owner address (same as in client profile) |
| `ChassisNumber` | string | `"1313454687"` | Vehicle VIN |
| `TypeEnginePower` | string/int | `"14"` | Engine power (cc or kW) |
| `NoPassengers` | integer | `4` | Number of seats |
| `MetalPlateNo` | string | `"4532"` | Registration plate |
| `Payload` | integer | `0` | Load capacity (kg) |
| `ManufactureYear` | string | `"2009"` | Year as string |
| `ColorID` | integer | `5` | From ColorsLookup |
| `CarID` | integer | `1` | From CarsLookup |
| `LicensingAuthorityID` | integer | `1` | From LicensingAuthoritiesLookup |
| `PriceDetailID` | integer | `14` | **Seems required** – value may come from pricing step; if not available, use a default (e.g., 14). |
| `ClientProfileId` | integer | `42018` | From Step 1 or 2 |

**Request Example:**
```json
{
  "Name": "ABDULLAH",
  "Address": "Libya",
  "ChassisNumber": "1313454687",
  "TypeEnginePower": "14",
  "NoPassengers": 4,
  "MetalPlateNo": "4532",
  "Payload": 0,
  "ManufactureYear": "2009",
  "ColorID": "5",
  "CarID": "1",
  "LicensingAuthorityID": "1",
  "PriceDetailID": 14,
  "ClientProfileId": 42018
}
```

**Response:**
```json
{
  "Code": 200,
  "Statues": true,
  "Messages": "تم حفظ بنجاح",
  "data": { "Id": 35561 }
}
```

→ Save `data.Id` as `ClientProfileVehicleId`.

---

### Step 4: Create Compulsory Policy

**Endpoint:** `POST /api/Compulsories/Post`

**Request Body:**
```json
{
  "Check": null,
  "ClientProfileId": 42018,
  "ClientProfileVehicleId": 35561,
  "PolicyDateFrom": "2026-04-02T12:20:19",
  "InsuranceDurationId": "6",
  "IsPolicyPaid": false,
  "VoucherCode": null
}
```

**Notes:**
- `PolicyDateFrom` – ISO format without milliseconds (e.g., `2026-04-02T12:20:19`). Use local time or UTC.
- `InsuranceDurationId` – **string**, not integer. From durations lookup.
- `IsPolicyPaid` – always `false` (payment handled by your wallet system).

**Response:**
```json
{
  "Code": 200,
  "Statues": true,
  "Messages": "تم حفظ بنجاح",
  "data": {
    "Id": 57058,
    "TotalPremium": 31,
    "EncryptedId": "CfDJ8It5gYFj1-xLqiSIbFILAMOGJiNzjcwXn74j6VDoY_MItkKgQX4j9RgqU1obi-EFk-9XYH0DHfcbOujuAqg4RCcoY3hUGA9NTPC47bXy1gXP0l-SsCamb7S4KmwrfmtkHw"
  }
}
```

→ Save `data.Id` as `policyId`. The `TotalPremium` is the total amount (including taxes). No `NetPremium` yet.

---

### Step 5: Fetch Complete Policy Details (Mandatory)

**Endpoint:** `GET /api/Compulsories/Get/{policyId}`

**Response:**
```json
{
  "Code": 200,
  "Statues": true,
  "Messages": "تم ارسال البيانات",
  "data": {
    "Id": 57058,
    "ChassisNumber": "1313454687",
    "TypeEnginePower": 14,
    "NoPassengers": 4,
    "MetalPlateNo": "4532",
    "Payload": 0,
    "ManufactureYear": 2009,
    "Color": "احمر",
    "Car": "اودي",
    "LicensingAuthority": "طرابلس",
    "DocumentType": "خاصة",
    "Name": "ABDULLAH",
    "Check": "fc07a386-b83e-49f0-afdb-8365c06971f4",
    "Address": "Libya",
    "Phone": "0911388788",
    "NetPremium": 28.8,
    "PolicyState": 1,
    "PolicyIssueDate": "2026-04-02T14:20:20.7027843",
    "TotalPremium": 31,
    "PolicyDateFrom": "2026-04-02",
    "PolicyDateTo": "2026-05-02",
    "EncryptedId": "CfDJ8It5gYFj1-xLqiSIbFILAMPj69nwi3GrXKEArhk7xHKdEfJgnkildY4oN0amD1P9XWPmic_dxsmMyrIZ3mQJakvjzJIdylsQAIMyOAzoGZVHCf54UuoKbvUzJWj_Byb2cw"
  }
}
```

**Critical Fields for Your Financial System:**
- `NetPremium` – base price before taxes (used for commission calculation).
- `TotalPremium` – final amount to deduct from wallet.
- `PolicyDateFrom` / `PolicyDateTo` – for policy validity.
- `PolicyState` – 1 = active/issued.

You must store the full `data` object in `order_item.product_details`.

---

## 3. Summary of Required User Inputs (Beneficiary Form)

From the UI, collect:

| Field | API Usage |
|-------|-----------|
| Owner name | `Name` in client profile and vehicle |
| Phone | `Phone` – normalized (e.g., remove leading zero, add `+218`) |
| Address | `Address` – street, city |
| Email | `Email` – optional |
| Vehicle type (dropdown) | `CarID` |
| Color (dropdown) | `ColorID` |
| Licensing authority (dropdown) | `LicensingAuthorityID` |
| Chassis number | `ChassisNumber` |
| Metal plate number | `MetalPlateNo` |
| Manufacture year | `ManufactureYear` (string) |
| Number of seats | `NoPassengers` (prefilled from price step) |
| Payload (kg) | `Payload` (prefilled from price step) |
| Insurance duration | `InsuranceDurationId` (from search step) |
| Policy start date | `PolicyDateFrom` (default today) |

**Note:** `PriceDetailID` may be a hidden field – use the value returned from the pricing endpoint or a default (e.g., 14). In the trace, it is always `14` regardless of the vehicle.

---

## 4. Error Handling

- **Duplicate phone**: Always call `GetByPhone` before attempting to create a client profile. If duplicate occurs despite this, reuse the existing `Id`.
- **Missing `PriceDetailID`**: If not provided by pricing API, use a fixed value (ask Al Baraka for default). In the trace it was `14`.
- **Any 4xx/5xx response**: Log full request/response; do not create order; return user‑friendly message (Arabic/English).
- **Timeout or network error**: Retry up to 3 times with exponential backoff.

---

## 5. Sequence Diagram (Text)

```
User submits beneficiary form
    │
    ▼
Backend: GET /ClientProfiles/GetByPhone?Phone=...
    │
    ├── Found → use existing ClientProfileId
    │
    └── Not found → POST /ClientProfiles/Post → get ClientProfileId
    │
    ▼
POST /ClientProfileVehicles/Post → get VehicleId
    │
    ▼
POST /Compulsories/Post → get policyId + TotalPremium
    │
    ▼
GET /Compulsories/Get/{policyId} → get full details (NetPremium, taxes, etc.)
    │
    ▼
Create Order & OrderItem, deduct wallet, post to ledger
    │
    ▼
Redirect to order details
```

---

## 6. Important Notes for AI

- **Do not skip the `GetByPhone` check** – it is mandatory to avoid `400` errors.
- **`InsuranceDurationId` must be sent as a string** (e.g., `"6"`), not integer.
- **`PolicyDateFrom` format** – use `Y-m-d\TH:i:s` (no milliseconds). Example: `2026-04-28T14:30:00`.
- **After policy creation, always call the `GET` endpoint** to retrieve `NetPremium` and other details – the initial POST response does not contain them.
- **Store the full `data` object from the GET response** in `order_item.product_details`.
- **Commission** is based on `NetPremium`, not `TotalPremium`.
