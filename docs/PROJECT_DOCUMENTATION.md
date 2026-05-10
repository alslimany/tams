# TAMS – Travel Agency Management System
## Full Project Documentation

---

## 1. Project Overview

**TAMS** (Travel Agency Management System) is a multi-tenant SaaS platform built for Libyan travel agencies. It enables agencies to search, book, and issue flight tickets and insurance policies through a unified dashboard. Each agency (tenant) operates in complete isolation with its own database, users, wallet, and provider configurations.

The platform is built on **Laravel 12 + Inertia.js v2 + React 19 + Tailwind CSS v4**, served via **Laravel Herd** locally and deployable to **Laravel Cloud**.

---

## 2. Tech Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Backend | Laravel | v12 |
| PHP | PHP | 8.4 |
| Multi-tenancy | Stancl/Tenancy | v3 |
| Auth | Laravel Fortify | v1 |
| API Auth | Laravel Sanctum | v4 |
| Frontend | React | v19 |
| SPA Bridge | Inertia.js | v2 |
| Styling | Tailwind CSS | v4 |
| UI Components | Shadcn UI | custom |
| Wallets | bavix/laravel-wallet | — |
| Ledger | abivia/ledger | — |
| Testing | Pest | v4 |
| Debugging | Laravel Telescope | v5 |
| Code Style | Laravel Pint | v1 |
| Route Helpers | Ziggy | v2 |

---

## 3. Architecture

### 3.1 Multi-Tenancy Model

The platform uses **Stancl/Tenancy** with database-per-tenant isolation:

- **Central (Landlord) Database** – stores global data: `tenants`, `domains`, `airports`, `route_availability_cache`, `flight_schedule_cache`, `landlord_settings`, `default_agency_settings`, `agency_wallet_transactions`.
- **Tenant Database** – each agency has its own SQLite/MySQL database containing: `users`, `orders`, `order_items`, `tenant_providers`, `agency_settings`, wallet tables, ledger tables, insurance provider tables.

### 3.2 Default Agency (Master Agency) Model

A central admin can designate one tenant as the **default agency**:
- Other tenants can be forced to use the default agency's airline providers (`force_use_default_agency = true`).
- Wallet deductions still happen in the buying agency's own wallet.
- Commission owed to the default agency is tracked in `order_item.master_commission_amount`.
- Settlement is manual via central admin reports.

### 3.3 Directory Structure

```
app/
├── Actions/
│   ├── Finance/          # Financial actions (wallet, ledger, orders)
│   ├── Fortify/          # Auth actions
│   └── Orders/           # Order processing actions
├── Console/Commands/     # Artisan commands
├── Contracts/Insurance/  # Insurance provider interface
├── DTOs/                 # Data Transfer Objects (Airline, Finance, Insurance, Videcom)
├── Exceptions/           # Custom exceptions
├── Http/
│   ├── Controllers/
│   │   ├── Auth/         # Standard auth controllers
│   │   ├── Landlord/     # Central admin controllers
│   │   └── Tenant/       # Agency-facing controllers
│   └── Middleware/       # Custom middleware
├── Models/
│   ├── Tenant/           # Tenant-scoped models
│   └── *.php             # Central models (Tenant, Domain, etc.)
├── Providers/            # Service providers
└── Services/
    ├── Airline/          # Flight search, provider factory, Videcom
    ├── Commission/       # Commission calculators
    ├── Finance/          # Ledger driver, commission
    ├── GlobalCache/      # Route availability, flight schedule cache
    ├── Insurance/        # Al Baraka insurance provider
    └── Orders/           # Order number generator

resources/js/
├── Components/           # Reusable UI components (Shadcn + custom)
├── Layouts/              # TenantLayout, LandlordLayout, GuestLayout
├── Pages/
│   ├── Auth/             # Login, Register, Password Reset
│   ├── Landlord/         # Admin panel pages
│   ├── Tenant/
│   │   ├── Bookings/     # Flight search, results, passenger info, completed
│   │   ├── Insurance/    # Insurance search, compulsory, travel beneficiary
│   │   ├── Reports/      # Sales, commissions, taxes, wallet, reconciliation
│   │   ├── Settings/     # Airline config, insurance config, general settings
│   │   └── Users/        # User management
│   └── Welcome.jsx
└── hooks/                # Custom React hooks

database/
├── migrations/           # Central DB migrations
└── migrations/tenant/    # Tenant DB migrations
```

---

## 4. Authentication & Authorization

### 4.1 Tenant Authentication
- **Laravel Fortify** handles login, registration, password reset, email verification, and 2FA (TOTP).
- Users belong to a tenant and have roles: `admin`, `manager`, `agent`.
- Middleware `EnsureUserRole` enforces role-based access.
- Middleware `CheckActiveUser` blocks deactivated users.
- Middleware `CheckTenantOperationalStatus` blocks access if the tenant is suspended.

### 4.2 Landlord Authentication
- Separate `LandlordUser` model with its own login at `/admin/login`.
- Protected by `EnsureLandlordAuthenticated` middleware.

### 4.3 Role Permissions

| Feature | Admin | Manager | Agent |
|---------|-------|---------|-------|
| Flight search & booking | ✅ | ✅ | ✅ |
| Insurance search & issue | ✅ | ✅ | ✅ |
| Ticket issue/void/refund | ✅ | ✅ | ❌ |
| Reports | ✅ | ✅ | ✅ |
| Reconciliation report | ✅ | ❌ | ❌ |
| User management | ✅ | ❌ | ❌ |
| Airline/Insurance config | ✅ | ❌ | ❌ |

---

## 5. Flight Booking Module

### 5.1 Flow
```
Search → Results (per provider) → Select Offer → Passenger Info → Confirm & Pay → Ticket Issued
```

### 5.2 Search & Results
- `BookingController::search()` – validates search params, creates a cache UUID (`flight_search_{uuid}`), redirects to results.
- `BookingController::results()` – loads search params from cache, renders `SearchResults.jsx`.
- `BookingController::fetchFlights()` – AJAX endpoint called per provider to fetch live availability.
- `BookingController::getReturnOptions()` – fetches return flight options for round-trip.
- `BookingController::calendarHints()` – returns cheapest fares per day for calendar display.

### 5.3 Offer Selection & Caching
- `BookingController::select()` – saves selected offer to cache (`selected_offer` key), renders `PassengerInfo.jsx`.
- `BookingController::passengers()` – GET endpoint to reload passenger page from cache on refresh.
- Cache TTL: 60 minutes. Cache stores: search params + selected offer + passengers + extras.

### 5.4 Booking & Issuance
- `BookingController::store()` – validates passengers, loads from cache if needed, calls Videcom API to issue ticket, creates Order + OrderItem, deducts wallet, posts to ledger.
- `TicketController::issue()` – re-issues a ticket (manager only).
- `TicketController::void()` – voids a ticket (manager only).
- `TicketController::refund()` – refunds a ticket (manager only).

### 5.5 Videcom Integration
- **API**: SOAP/XML over HTTPS at `https://customer2.videcom.com/{airline}/vars/public/webservices/VRSXMLWebservice3.asmx`
- **Airlines supported**: Oya (YI), Medsky (BM), Buraq (UZ), Berniq (NB), Libyan Wings (YL), Libya Express (LB), Global (5S), Crown (FQ)
- **Key classes**:
  - `VidecomClient` – sends commands, parses XML responses
  - `BaseVidecomAirline` – base class for all airlines
  - `VidecomPnrParser` – parses PNR XML into structured data
  - `VidecomAncillaryCatalog` – handles ancillary services (seats, baggage)
  - `ProviderFactory` – creates airline provider instances
  - `AgencyProviderResolver` – resolves providers (own vs default agency)
  - `RoundTripPriceManager` – handles combined/split round-trip pricing

### 5.6 Route Availability Cache
- Global table `route_availability_cache` in central DB.
- Tracks which airlines have flights on (origin, destination) pairs.
- Reduces unnecessary API calls during search.

### 5.7 Open Reservation
- Supports `QQ` (open/unconfirmed) and `NN` (confirmed) reservation types.
- `openReservationAvailability()` checks if a provider supports open reservations.

---

## 6. Insurance Module

### 6.1 Supported Products
| Product | Provider | Status |
|---------|----------|--------|
| Compulsory (vehicle) | Al Baraka | ✅ Implemented |
| Travel | Al Baraka | ✅ Implemented |
| Orange (cross-border) | Al Baraka | 🔄 Partial |

### 6.2 Compulsory Insurance Flow
```
Search (price) → Beneficiary Form → Issue Policy → Order Created
```
1. **Price**: `POST /api/Compulsories/CheckPolicyPrices` with document type, duration, seats, payload.
2. **Check existing client**: `GET /api/ClientProfiles/GetByPhone` (avoids duplicate error).
3. **Create client profile** (if not found): `POST /api/ClientProfiles/Post`.
4. **Create vehicle**: `POST /api/ClientProfileVehicles/Post`.
5. **Create policy**: `POST /api/Compulsories/Post`.
6. **Fetch full details**: `GET /api/Compulsories/Get/{policyId}` – gets `NetPremium`, `TotalPremium`.
7. Create Order + OrderItem, deduct wallet, post to ledger.

### 6.3 Travel Insurance Flow
```
Search (price by age/zone/duration) → Beneficiary Form → Issue Policy → Order Created
```

### 6.4 Insurance Configuration
- `TenantInsuranceProvider` model stores credentials (Bearer token, base URL) per tenant.
- Commission rates stored per product type: `commission_compulsory`, `commission_travel`, `commission_orange`.
- `InsuranceConfigController` allows admin to configure and test the connection.

### 6.5 Policy Management
- `InsurancePolicyController` handles: report download (PDF), cancellation submission, cancellation finalization.
- Policy cancellation updates order item status and reverses wallet/ledger entries.

---

## 7. Financial System

### 7.1 Wallets
- `bavix/laravel-wallet` – each tenant has wallets per currency (LYD, USD, EUR, TND).
- `EnsureSufficientWalletBalance` middleware blocks booking if balance is insufficient.
- Landlord can top up agency wallets via the admin panel.

### 7.2 Orders & Order Items
- `Order` – polymorphic owner (user), status, grand_total, currency, payment_method, contact.
- `OrderItem` – product_type (flight/insurance), product_subtype (oneway/roundtrip/compulsory/travel/orange), net_fare, taxes (JSON), commission_percent, commission_amount, master_commission_amount, provider_reference, wallet_transaction_id, ledger_entry_id.

### 7.3 Financial Flow
1. Successful API booking → create `Order` + `OrderItem` in DB transaction.
2. Calculate commission from provider settings.
3. Determine financial source:
   - Own credentials → record in `AirlineAccount` (external), no wallet movement.
   - Default agency supply → deduct from agency wallet via `ProcessWalletTransactions`.
4. Post to double-entry ledger via `PostToLedger` action.
5. Log status in `OrderStatusLog`.

### 7.4 Double-Entry Ledger
- `abivia/ledger` – journal entries for revenue, expense, liabilities.
- `InitializeTenantLedger` action sets up chart of accounts per tenant.
- `AbiviaLedgerDriver` wraps the ledger package.

### 7.5 Commission
- **Flight**: `commission_domestic` / `commission_international` on `TenantProvider`.
- **Insurance**: `commission_compulsory` / `commission_travel` / `commission_orange` on `TenantInsuranceProvider`.
- Commission = net_fare × rate / 100.
- Master agency commission tracked separately in `master_commission_amount`.

### 7.6 Artisan Finance Commands
| Command | Purpose |
|---------|---------|
| `finance:backfill-ledger` | Backfill ledger entries for existing orders |
| `finance:backfill-orders` | Backfill order records |
| `finance:reconcile` | Reconcile wallet vs orders |
| `finance:settle-master-commissions` | Settle commissions owed to default agency |
| `finance:settlement-report` | Generate settlement report |
| `finance:initialize-tenant-ledger` | Initialize ledger for a tenant |

---

## 8. Reports Module

All reports are accessible to all roles; reconciliation is admin-only.

| Report | Route | Description |
|--------|-------|-------------|
| Daily Sales | `reports/sales` | Sales summary by date |
| Commissions | `reports/commissions` | Commission breakdown |
| Taxes | `reports/taxes` | Tax breakdown |
| Wallet Transactions | `wallet/transactions` | Wallet history |
| Reconciliation | `reports/reconciliation` | Wallet vs orders (admin only) |

---

## 9. Landlord (Central Admin) Panel

Accessible at `/admin` with separate authentication.

### Features
- **Tenant Management** – list, view, activate/suspend tenants.
- **Tenant Users** – create, update, delete users within a tenant.
- **Wallet Top-up** – deposit funds into a tenant's wallet.
- **Default Agency Settings** – set/unset default agency, configure `force_use_default_agency`, `can_use_own_airline_credentials`, `master_commission_percent`.
- **Airport Management** – CRUD for airports (used in flight search autocomplete).
- **Flight Cache Settings** – configure global route availability and schedule cache.

---

## 10. Settings Module (Tenant)

### Airline Configuration (Admin only)
- Add/edit airline providers (Videcom credentials per airline).
- Test connection to Videcom API.
- Deposit funds to airline account.
- Toggle provider active/inactive.

### Insurance Configuration (Admin only)
- Add/edit Al Baraka insurance provider credentials.
- Set commission rates per product type.
- Deposit funds to insurance account.
- Test connection.

### General Settings (Admin only)
- Agency name, logo, and other tenant-level settings.

---

## 11. Internationalization (i18n)

- Supported languages: **English (en)**, **Arabic (ar)**, **French (fr)**.
- Translation files: `resources/lang/{locale}/common.php`.
- Frontend uses a custom `useTranslation` hook that reads from Inertia shared props.
- RTL support via `dir="rtl"` for Arabic.
- Language switcher available in the UI.
- `CompileTranslations` Artisan command compiles PHP translations to JSON for frontend.

---

## 12. Frontend Architecture

### Layouts
- `TenantNavbarLayout` – navbar-based layout for booking flows.
- `TenantSidebarLayout` – sidebar layout for dashboard/settings.
- `LandlordLayout` – admin panel layout.
- `GuestLayout` – for auth pages.

### Key Pages
| Page | Path | Description |
|------|------|-------------|
| Flight Search | `Tenant/Bookings/Search.jsx` | One-way / round-trip search form |
| Search Results | `Tenant/Bookings/SearchResults.jsx` | Live results per provider, offer selection |
| Passenger Info | `Tenant/Bookings/PassengerInfo.jsx` | Passenger form, extras, seat map, review |
| Booking Completed | `Tenant/Bookings/Completed.jsx` | Confirmation page |
| Insurance Search | `Tenant/Insurance/Search.jsx` | Insurance type selection + pricing |
| Compulsory Search | `Tenant/Insurance/CompulsorySearch.jsx` | Compulsory-specific search |
| Compulsory Beneficiary | `Tenant/Insurance/CompulsoryBeneficiary.jsx` | Beneficiary + vehicle form |
| Travel Beneficiary | `Tenant/Insurance/TravelBeneficiary.jsx` | Travel insurance beneficiary form |
| Dashboard | `Tenant/Dashboard.jsx` | Stats, wallet, recent bookings |
| Orders | `Orders/Index.jsx` + `Orders/Show.jsx` | Order list and detail |

### UI Components
- Shadcn UI components: `Button`, `Card`, `Dialog`, `Input`, `Label`, `Select`, `Table`, `Tabs`, `Badge`, `Popover`, `Calendar`, `Chart`, `Sidebar`, etc.
- Custom: `FlightGroupCard`, `AsyncAirportSelect`, `LanguageSwitcher`, `UserMenu`.

---

## 13. Testing

- **Framework**: Pest v4 (PHPUnit v12 underneath).
- **Test types**: Feature tests (majority), Unit tests.
- **Coverage areas**:
  - Auth (login, register, 2FA, password reset, email verification)
  - Flight booking flow (search, select, store, ticket issue/void)
  - Insurance flow (compulsory, travel, cancellation)
  - Financial system (wallet, ledger, commission, reconciliation)
  - Landlord panel (tenant management, master agency)
  - Reports
  - Videcom integration (PNR parser, booking commands, round-trip pricing)
  - Route availability cache
  - Role-based access control

---

## 14. Key Middleware

| Middleware | Purpose |
|-----------|---------|
| `InitializeTenancyByDomain` | Switches DB context to tenant |
| `PreventAccessFromCentralDomains` | Blocks tenant routes from central domain |
| `EnsureLandlordAuthenticated` | Protects landlord routes |
| `CheckActiveUser` | Blocks deactivated users |
| `CheckTenantOperationalStatus` | Blocks suspended tenants |
| `EnsureUserRole` | Role-based access (`role:admin,manager`) |
| `EnsureSufficientWalletBalance` | Blocks booking if wallet insufficient |
| `HandleInertiaRequests` | Shares global props with Inertia |

---

## 15. Environment & Configuration

- **Local**: Laravel Herd at `https://tams.test`
- **Videcom**: Per-airline credentials in `tenant_providers.credentials` (JSON).
- **Al Baraka**: Bearer token in `tenant_insurance_providers.credentials` (JSON).
- **Cache**: File-based (local), Redis (production recommended).
- **Queue**: Sync (local), database/Redis (production).

---

## 16. Artisan Commands Reference

| Command | Description |
|---------|-------------|
| `cache:flight-schedules` | Cache flight schedules from Videcom |
| `translations:compile` | Compile PHP translations to JSON |
| `finance:backfill-ledger` | Backfill ledger entries |
| `finance:backfill-orders` | Backfill order records |
| `finance:reconcile` | Reconcile wallet vs orders |
| `finance:settle-master-commissions` | Settle master agency commissions |
| `finance:settlement-report` | Generate settlement report |
| `finance:initialize-tenant-ledger` | Initialize ledger for tenant |
| `finance:backfill-compulsory-insurance` | Backfill insurance financial records |
| `airports:import` | Import airports from data file |
| `tenants:fix-admin-roles` | Fix admin roles for existing tenants |

---

*Last updated: May 2026*
