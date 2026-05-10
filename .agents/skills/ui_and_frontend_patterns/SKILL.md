---
name: ui-and-frontend-patterns
description: "Inertia + React + Shadcn UI patterns, layouts (tenant/landlord), booking UX, form handling with useForm, state management, RTL support, and common pages."
license: MIT
metadata:
  author: booknow
---

# UI/UX Patterns (Inertia + React + Shadcn)

## Canonical Booking UX Rule

Read `booking-ux-patterns` before building or changing any flight, insurance, hotel, eSIM, or other bookable product flow.

Every bookable product must follow:

```text
Search Form → Offer Display → Offer Selection → Booking/Completion Form → Confirmation Page
```

Booking details pages must use the two-column pattern:

- Left: form and step tabs.
- Right: sticky summary sidebar on desktop.

Do not create a new booking flow layout unless explicitly approved by the product owner.

## Layouts
- `resources/js/Layouts/TenantLayout.jsx` – for agency dashboard (sidebar with navigation, wallet display).
- `resources/js/Layouts/LandlordLayout.jsx` – for central admin panel.

## Shadcn Components
- Use `<Table>`, `<Card>`, `<Dialog>`, `<Select>`, `<Button>`, `<Input>`, `<DatePicker>`.
- Tailwind CSS for styling.

## Form Handling
- Use `useForm` from `@inertiajs/react` for POST/PUT requests.
- Validation errors displayed via `form.errors`.
- Booking forms should preserve cache UUIDs so refresh/retry does not lose selected offers or entered data.

## State Management
- Local React state for forms.
- Global: Inertia shared props (user, tenant settings).

## RTL Support
- Arabic language supported via `dir="rtl"` and CSS logical properties.
- Use `useTranslation()` for all visible user-facing text.
- Required languages are English, Arabic, and French.

## Common Pages
- Flight search (one‑way, round‑trip) → results → passenger details → booking completed/confirmation → order show.
- Insurance search (compulsory, travel, orange) → quote/offer → beneficiary/details form → issued/confirmation → order show.
- Wallet dashboard (balances, transaction history).
- Sales reports (tables, charts).

## Offer Display Patterns

Use one of two approved offer display patterns:

1. Results page offers for multi-provider comparison, as in flight search results.
2. Inline offer below search form for simple single-quote products, as in compulsory insurance.

Selection must store the offer in cache with the search UUID before navigating to details.

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
