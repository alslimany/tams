# eSIM Integration Plan

**Status**: Approved — In Progress  
**Created**: 2026-05-20  
**Last Updated**: 2026-05-20

---

## Overview

Integrate eSIM as a first-class provider in the Booknow platform, following the same pattern as hotel and insurance providers. eSIM packages are sold as `order_items` (`item_type = 'esim'`), not a separate orders table. The L2 Travel eSIM API is the first provider; the architecture is designed to be provider-agnostic for future additions.

---

## Architecture Decisions

- `TenantEsimProvider` model in tenant DB — mirrors `TenantHotelProvider`
- `provider_type = 'esim'` in `provider_allocations` for agency/merchant network sharing
- eSIM purchases = `OrderItem` with `item_type = 'esim'`, `item_details` JSON (package info, iccid, activation code, etc.)
- **No separate `esim_orders` table**
- Single commission field: `commission_esim` (not split by product type)
- Results page is provider-agnostic — future providers plug in via `ESimProviderManager`
- Wallet currency: `USD` (slug: `ESIM_USD`)
- Sub-journal: `ESIM`
- L2 API base: `https://l2travelesim.com/api/v2/` — Bearer token auth stored in `credentials` JSON

---

## Phases

### Phase 1 — Provider Infrastructure (Backend)

- [x] **Migration**: `tenant_esim_providers` table
  - `id`, `provider_type` (default `l2`), `name`, `credentials` (JSON: `base_url`, `token`), `is_active`, `commission_esim` (decimal 5,2), timestamps
- [x] **Model**: `App\Models\Tenant\TenantEsimProvider`
  - Implements `WalletInterface`, uses `HasWallet` + `HasWallets`
  - `commissionForProductType(string $productType): float`
  - `getOrCreateCurrencyWallet(string $currency = 'USD'): Wallet` (slug: `ESIM_USD`)
  - `getBalance(string $currency = 'USD'): float`
- [x] **Contract**: `App\Contracts\ESim\ESimProviderInterface`
  - `catalogue(ESimCatalogueRequest $request): array` — list packages filtered by country/data/duration
  - `bundles(string $packageId): array` — get bundles for a package
  - `processOrder(ESimOrderRequest $request): ESimOrderResult`
  - `orderDetails(string $orderId): array`
- [x] **DTOs**:
  - `App\DTOs\ESim\ESimPackage` — id, name, country, data_mb, validity_days, price, currency, provider
  - `App\DTOs\ESim\ESimOrderRequest` — package_id, quantity, customer details
  - `App\DTOs\ESim\ESimOrderResult` — order_id, iccid, activation_code, qr_code_url, status
- [x] **L2 Adapter**: `App\Services\ESim\Providers\L2Provider implements ESimProviderInterface`
  - Bearer token from `$config->credentials['token']`
  - Base URL from `$config->credentials['base_url']`
  - Implements: catalogue, bundles, processOrder, orderDetails
- [x] **Factory**: `App\Services\ESim\ESimProviderFactory::make(TenantEsimProvider $config)`
- [x] **Manager**: `App\Services\ESim\ESimProviderManager`
  - Mirrors `HotelProviderManager` exactly
  - Resolves merchant network allocation first (`provider_type = 'esim'`, `source_provider_model = TenantEsimProvider::class`)
  - Falls back to own active provider
- [x] **`ProviderSourceResolver`**: added `TenantEsimProvider::class` dispatch + `resolveTenantEsimProvider()`

---

### Phase 2 — Settings / Config

- [ ] **Controller**: `App\Http\Controllers\Tenant\ESimConfigController`
  - `index()` — list configured providers with balance, commission, status
  - `store(Request $request)` — upsert provider config + optional opening balance
  - `deposit(Request $request)` — add funds to provider wallet
- [ ] **Routes** in `routes/tenant.php` (inside settings group):
  ```php
  Route::get('settings/esim', [ESimConfigController::class, 'index'])->name('settings.esim.index');
  Route::post('settings/esim', [ESimConfigController::class, 'store'])->name('settings.esim.store');
  Route::post('settings/esim/deposit', [ESimConfigController::class, 'deposit'])->name('settings.esim.deposit');
  ```
- [ ] **Page**: `resources/js/Pages/Tenant/Settings/ESim.jsx`
  - Mirrors `Insurance.jsx`
  - Fields: name, base_url, token, commission_esim, is_active, initial_balance, deposit
- [ ] **Sidebar nav**: add eSIM config entry to `TenantLayout.jsx`
  - `{ name: t('tenant.nav.esim_config'), route: 'settings.esim.index', icon: SimCardIcon }`
- [ ] **Translation keys**: add `tenant.nav.esim_config` to all 3 locales + compile

---

### Phase 3 — Booking Flow (Backend)

- [ ] **`ESimSearchController`**:
  - `index()` — render search form page (Inertia)
  - `search(Request $request)` — call `ESimProviderManager::catalogue()`, cache results (UUID key), redirect to results
- [ ] **`ESimResultsController`**:
  - `results(string $uuid)` — load from cache, pass packages + search params to React
- [ ] **`ESimBookController`**:
  - `select(Request $request)` — store selected package in cache, redirect to checkout
  - `checkout(string $uuid)` — render checkout page with package details
  - `purchase(Request $request)` — call `processOrder`, create `Order` + `OrderItem`, deduct wallet, post ledger, redirect to order show
- [ ] **Routes** in `routes/tenant.php` (inside bookings group):
  ```php
  Route::get('esim', [ESimSearchController::class, 'index'])->name('esim.index');
  Route::post('esim/search', [ESimSearchController::class, 'search'])->name('esim.search');
  Route::get('esim/results/{uuid}', [ESimResultsController::class, 'results'])->name('esim.results');
  Route::post('esim/select', [ESimBookController::class, 'select'])->name('esim.select');
  Route::get('esim/checkout/{uuid}', [ESimBookController::class, 'checkout'])->name('esim.checkout');
  Route::post('esim/purchase', [ESimBookController::class, 'purchase'])->name('esim.purchase');
  ```
- [ ] **`OrderItem` `item_details` shape for eSIM**:
  ```json
  {
    "package_id": "...",
    "package_name": "...",
    "country": "LY",
    "data_mb": 5120,
    "validity_days": 30,
    "provider": "l2",
    "provider_order_id": "...",
    "iccid": "...",
    "activation_code": "...",
    "qr_code_url": "...",
    "status": "active"
  }
  ```

---

### Phase 4 — Search & Results UI

- [ ] **`resources/js/Pages/Tenant/ESim/Search.jsx`**
  - Unified search form — provider-agnostic
  - Fields: destination country (searchable select), data size (optional), validity/duration (optional)
  - Works for any future eSIM provider
- [ ] **`resources/js/Pages/Tenant/ESim/Results.jsx`**
  - Left `FilterSidebar` (mirrors hotel results):
    - Price range (dual-handle slider)
    - Data size (GB checkboxes: 1GB, 3GB, 5GB, 10GB, 20GB+)
    - Validity days (checkboxes: 7, 14, 30, 60, 90 days)
    - Provider badge filter (for future multi-provider)
  - Package cards grid (right side)
  - Each card: package name, country flag, data size, validity, price, provider badge, "Buy Now" button
- [ ] **`resources/js/Pages/Tenant/ESim/Checkout.jsx`**
  - Package summary, price breakdown (base + commission), confirm purchase button
- [ ] **Sidebar nav**: add eSIM booking entry to `TenantLayout.jsx`
  - `{ name: t('tenant.nav.esim'), route: 'esim.index', icon: SimCardIcon }`

---

### Phase 5 — Financial & Accounting

- [ ] **`ProcessESimProviderWalletTransactions`** action (`App\Actions\Finance\`)
  - `assertCanWithdraw(TenantEsimProvider $provider, string $currency, float $amount): void`
  - `execute(Order $order, TenantEsimProvider $provider): void`
  - Deducts provider wallet, records transaction metadata on order item
  - Mirrors `ProcessHotelProviderWalletTransactions`
- [ ] **Ledger journal entry for eSIM sale** (balanced, 4 lines):
  - Dr `1310` (Accounts Receivable / Customer)
  - Cr `4100` (Revenue)
  - Dr `5100` (Cost of Goods — Provider Cost)
  - Cr `1210` (Wallet / Cash)
  - Sub-journal: `ESIM`
  - `JournalEntry.extra`: `{"reference":"order:{id}|item:{item_id}"}`
- [ ] **`InitializeTenantLedger`**: add `ESIM` sub-journal registration if not already present

---

### Phase 6 — Agency/Merchant Network

- [ ] **`provider_allocations`** support for eSIM:
  - `provider_type = 'esim'`
  - `source_provider_model = TenantEsimProvider::class`
  - `provider_driver = 'l2'` (or future provider slug)
- [ ] **`ESimProviderManager`** resolves merchant allocation first (same pattern as `HotelProviderManager::activeNetworkProviderWithSource()`)
- [ ] Agency can share eSIM provider with merchants via the existing network membership + provider allocation flow — no new tables needed

---

### Phase 7 — Tests

- [ ] **`ESimConfigControllerTest`** (Feature):
  - Store provider config
  - Deposit to provider wallet
  - Validation errors
- [ ] **`ESimBookingFlowTest`** (Feature):
  - Search returns packages
  - Purchase creates `Order` + `OrderItem` with `item_type = 'esim'`
  - Provider wallet is deducted
  - Ledger entry is posted (ESIM sub-journal)
  - Insufficient balance throws exception

---

## File Map

| File | Type | Notes |
|------|------|-------|
| `database/migrations/tenant/XXXX_create_tenant_esim_providers_table.php` | Migration | Tenant DB |
| `app/Models/Tenant/TenantEsimProvider.php` | Model | HasWallet |
| `app/Contracts/ESim/ESimProviderInterface.php` | Contract | |
| `app/DTOs/ESim/ESimPackage.php` | DTO | |
| `app/DTOs/ESim/ESimOrderRequest.php` | DTO | |
| `app/DTOs/ESim/ESimOrderResult.php` | DTO | |
| `app/Services/ESim/Providers/L2Provider.php` | Service | L2 adapter |
| `app/Services/ESim/ESimProviderFactory.php` | Service | |
| `app/Services/ESim/ESimProviderManager.php` | Service | Network-aware |
| `app/Services/ESim/ESimApiException.php` | Exception | |
| `app/Http/Controllers/Tenant/ESimConfigController.php` | Controller | Settings |
| `app/Http/Controllers/Tenant/ESimSearchController.php` | Controller | Search |
| `app/Http/Controllers/Tenant/ESimResultsController.php` | Controller | Results |
| `app/Http/Controllers/Tenant/ESimBookController.php` | Controller | Checkout + Purchase |
| `app/Actions/Finance/ProcessESimProviderWalletTransactions.php` | Action | Financial |
| `resources/js/Pages/Tenant/Settings/ESim.jsx` | React | Config page |
| `resources/js/Pages/Tenant/ESim/Search.jsx` | React | Search form |
| `resources/js/Pages/Tenant/ESim/Results.jsx` | React | Filterable results |
| `resources/js/Pages/Tenant/ESim/Checkout.jsx` | React | Checkout |
| `tests/Feature/Tenant/ESimConfigControllerTest.php` | Test | |
| `tests/Feature/Tenant/ESimBookingFlowTest.php` | Test | |

---

## Progress Tracker

| Phase | Status |
|-------|--------|
| Phase 1 — Provider Infrastructure | ✅ Complete |
| Phase 2 — Settings / Config | ✅ Complete |
| Phase 3 — Booking Flow (Backend) | ⬜ Not started |
| Phase 4 — Search & Results UI | ⬜ Not started |
| Phase 5 — Financial & Accounting | ⬜ Not started |
| Phase 6 — Agency/Merchant Network | ⬜ Not started |
| Phase 7 — Tests | ⬜ Not started |
