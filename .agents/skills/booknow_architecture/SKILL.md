---
name: booknow-architecture
description: "Overall platform architecture, multi-tenancy (Stancl), tech stack (Laravel 10, Inertia, React, Shadcn), tenant vs central database, directory structure, and key workflows."
license: MIT
metadata:
  author: booknow
---

# Booknow V2 – Platform Architecture

## Overview
Multi‑tenant travel platform (Flights, Hotels, Insurance, eSIM) built on Laravel 10 + Stancl/Tenancy. Each tenant (agency) has its own database. Central (landlord) database stores tenant metadata, domains, and global caches.

## Key Concepts
- **Tenant**: An agency. Each tenant has its own DB, users, orders, wallets, and provider configurations.
- **Central (Landlord) DB**: Stores `tenants`, `domains`, `route_availability_cache`, `flight_schedule_cache`, and central admin settings.
- **Default Agency (Master Agency)** : A special tenant that can supply its airline credentials to other tenants. Central admin can force tenants to use it.
- **Agency vs Merchant** (future): Agency owns providers; Merchant buys from Agencies (not yet implemented – planned).

## Core Tech Stack
- Laravel 10, PHP 8.2+
- Stancl/Tenancy (multi‑database)
- Inertia.js + React + Shadcn UI
- `bavix/laravel-wallet` (wallets per tenant/currency)
- `abivia/ledger` (double‑entry accounting)
- Videcom SOAP/XML API for flights
- Al Baraka REST API for insurance

## Directory Structure (tenant and central)
- Central app: `app/` (landlord routes, admin panel)
- Tenant app: `app/Tenant/` (or use `app/` with tenancy identification)
- Routes: `routes/tenant.php` for agency routes, `routes/web.php` for central.

## Database Isolation
- Each tenant DB has: `users`, `orders`, `order_items`, `tenant_providers`, `agency_settings`, wallet tables, ledger tables.
- Central DB has: `tenants`, `domains`, `agency_wallet_transactions`, `route_availability_cache`, etc.

## File Naming Conventions
- Controllers: `*Controller.php` in `App\Http\Controllers\Tenant` or `Landlord`
- Services: `App\Services\Videcom`, `App\Services\Insurance`, `App\Services\Finance`
- Actions: `App\Actions\Finance\CreateOrderFromBookingData.php`
- DTOs: `App\DataTransferObjects\...`

## Environment Configuration
- Videcom: per‑airline credentials in `tenant_providers` (JSON).
- Al Baraka: credentials in `tenant_insurance_providers` (JSON).
- Central admin config in `.env` and `config/tenancy.php`.

## Key Workflows
1. Flight search → pricing → booking → ticket issuance → order creation → wallet deduction → ledger posting.
2. Insurance search → quote → beneficiary form → policy creation → order creation → wallet deduction.
3. Central admin top‑up agency wallets, toggle agency permissions, set default agency.