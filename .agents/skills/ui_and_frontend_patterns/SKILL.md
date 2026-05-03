---
name: ui-and-frontend-patterns
description: "Inertia + React + Shadcn UI patterns, layouts (tenant/landlord), form handling with useForm, state management, RTL support, and common pages (flight search, insurance, wallet, reports)."
license: MIT
metadata:
  author: booknow
---

# UI/UX Patterns (Inertia + React + Shadcn)

## Layouts
- `resources/js/Layouts/TenantLayout.jsx` – for agency dashboard (sidebar with navigation, wallet display).
- `resources/js/Layouts/LandlordLayout.jsx` – for central admin panel.

## Shadcn Components
- Use `<Table>`, `<Card>`, `<Dialog>`, `<Select>`, `<Button>`, `<Input>`, `<DatePicker>`.
- Tailwind CSS for styling.

## Form Handling
- Use `useForm` from `@inertiajs/react` for POST/PUT requests.
- Validation errors displayed via `form.errors`.

## State Management
- Local React state for forms.
- Global: Inertia shared props (user, tenant settings).

## RTL Support
- Arabic language supported via `dir="rtl"` and CSS logical properties.
- `react-i18next` for translations (optional).

## Common Pages
- Flight search (one‑way, round‑trip) → results → passenger details → booking → order show.
- Insurance search (compulsory, travel, orange) → price → beneficiary form → order show.
- Wallet dashboard (balances, transaction history).
- Sales reports (tables, charts).

## Navigation Items (Tenant)
- Dashboard
- Flights (Search, My Bookings)
- Insurance (Compulsory, Travel, Orange)
- Wallet & Finance
- Reports (Sales, Commissions)
- Settings (if allowed own providers)

## Central Admin Navigation
- Tenants list
- Default agency settings
- Agency wallet top‑up
- Route cache management (optional)