---
name: booking-ux-patterns
description: "Enforces the standard TAMS booking UI/UX patterns for search forms, offer display, offer selection, booking/checkout pages, cache recovery, and confirmation flows. Required for flights, insurance, hotels, eSIM, and any new bookable product."
license: MIT
metadata:
  author: booknow
---

# TAMS Booking UX Patterns

## Mandatory Rule

Every bookable product in TAMS must follow the same user journey and visual structure used by the existing flight and insurance flows. Do not invent a new booking pattern unless the product owner explicitly approves it.

Universal flow:

```text
Search Form → Offer Display → Offer Selection → Booking/Completion Form → Confirmation Page
```

This applies to:

- Flights
- Compulsory insurance
- Travel insurance
- Orange insurance
- Hotels
- eSIM
- Any future bookable product

---

## 1. Search Form Pattern

Search pages must be standalone pages or a clearly isolated search card.

Required structure:

- Hero/page heading, if appropriate.
- Main card using project UI components: `Card`, `CardHeader`, `CardTitle`, `CardContent`.
- Product/trip type selector near the top of the card.
- Fields grouped in responsive grid rows.
- Primary search/quote button with loading state.
- Inline validation or quote errors below the form.

Examples to follow:

- Flight search: `resources/js/Pages/Tenant/Bookings/Search.jsx`
- Insurance search: `resources/js/Pages/Tenant/Insurance/Search.jsx`
- Compulsory search: `resources/js/Pages/Tenant/Insurance/CompulsorySearch.jsx`

### Search Cache Rule

For products with multi-step checkout, the backend must create or reuse a cache UUID and store search params.

Pattern:

```php
$uuid = (string) Str::uuid();
Cache::put("{product}_search_{$uuid}", $validated, now()->addMinutes(60));
```

Use the UUID in follow-up routes so refresh/retry works.

---

## 2. Offer Display Patterns

TAMS has two valid offer display patterns. Choose the one that matches the product.

### Pattern A — Results Page Offers

Use when:

- Multiple providers return offers.
- Multiple offers must be compared.
- Offers need grouping, sorting, dialogs, or round-trip pairing.

Canonical example:

- Flight results: `resources/js/Pages/Tenant/Bookings/SearchResults.jsx`

Required behavior:

- Search redirects to a results page using the cache UUID.
- Results page reloads search params from cache.
- Offers may be fetched asynchronously per provider.
- Errors are shown per provider/result area.
- Offer details are shown in dialogs/cards.
- Selected offer is clearly shown before continuing.
- Continue action persists selected offer to cache.

### Pattern B — Inline Offer Below Search Form

Use when:

- Search returns one quote/offer.
- Product pricing is simple.
- User should adjust the search and re-quote without leaving the page.

Canonical example:

- Compulsory insurance offer shown below `resources/js/Pages/Tenant/Insurance/Search.jsx`

Required behavior:

- Search form stays visible.
- Offer card appears below the form.
- Offer card shows provider, product type, selected inputs, price, currency.
- Primary action button says `Select Policy`, `Continue`, or product-specific equivalent.
- Errors appear inline below the form.

---

## 3. Offer Selection Cache Rule

When a user selects an offer, save it to the same search cache entry.

Required payload shape:

```php
[
    // original search params...
    'selected_offer' => [
        'provider_id' => $providerId,
        'provider_type' => $providerType,
        'offer' => $offerPayload,
        'currency' => $currency,
        'total' => $total,
        'selected_at' => now()->toDateTimeString(),
    ],
]
```

Do not rely only on POST body data for the booking form page. Users must be able to refresh or retry without returning to search results.

Required reload route pattern:

```php
Route::get('{product}/details/{uuid}', [Controller::class, 'details'])->name('{product}.details');
```

If cache is missing, redirect to search with a user-friendly expiry message.

---

## 4. Booking/Completion Form Layout — Mandatory

Every booking details page must use a two-column layout:

- Left column: form and step tabs.
- Right column: sticky summary card.

Canonical examples:

- Flight: `resources/js/Pages/Tenant/Bookings/PassengerInfo.jsx`
- Compulsory insurance: `resources/js/Pages/Tenant/Insurance/CompulsoryBeneficiary.jsx`

Required layout:

```jsx
<div className="mx-auto grid max-w-7xl grid-cols-1 gap-8 py-8 lg:grid-cols-3">
    <div className="space-y-8 lg:col-span-2">
        {/* form card + steps */}
    </div>

    <div className="hidden lg:block">
        <div className="sticky top-8">
            {/* summary card */}
        </div>
    </div>
</div>
```

Do not create full-width checkout forms without a summary sidebar.

---

## 5. Step Tabs Pattern

Booking forms should show progress using disabled `TabsTrigger` controls.

Common labels:

- `1. Passengers / Data`
- `2. Extras / Payment`
- `3. Review / Confirm`

Rules:

- Tabs are visual indicators, not free navigation.
- The page controls the active step via state.
- Continue/back buttons move between steps.
- Final step performs issue/book action.

---

## 6. Summary Sidebar Pattern

The sticky summary column must include:

- Product or offer title.
- Provider.
- Product type/subtype.
- Currency.
- Base fare/net amount.
- Taxes/fees.
- Selected extras, if any.
- Total to pay in prominent typography.

This summary must update as extras or selected services change.

---

## 7. Confirmation Page Rule

Successful issuance must redirect to a dedicated confirmation page.

Do not show final success only inline in the form.

Confirmation page should include:

- Order number/reference.
- Provider reference (PNR, policy ID, ticket number, etc.).
- Total paid.
- Product details.
- Download/print/report action if applicable.
- Link to order details.

---

## 8. Error Handling

- Search errors: inline under search form.
- Provider errors: per provider/offer area.
- Form validation errors: next to fields via `form.errors`.
- Issuance errors: do not clear cache; keep form data for retry.
- Expired cache: redirect to search with clear message.

---

## 9. Internationalization

All visible UI text must use translations through `useTranslation()`.

Required languages:

- English
- Arabic
- French

Do not add hardcoded user-facing English strings in React pages.

---

## 10. AI Checklist

Before implementing any new booking flow, confirm:

- [ ] Search form follows project card/grid pattern.
- [ ] Correct offer display pattern selected: results page or inline offer.
- [ ] Search params are cached with UUID.
- [ ] Selected offer is saved to the same cache key.
- [ ] Details page has a GET reload route using UUID.
- [ ] Booking form uses two-column layout.
- [ ] Summary sidebar is sticky on desktop.
- [ ] Final success redirects to confirmation page.
- [ ] Issuance failure preserves cache.
- [ ] All UI text is translated.
- [ ] Financial validation follows `financial-and-wallet-system` skill.
