# Booknow V2 – Skills Index

This file lists all project skill files, their purpose, and when an AI agent should read them. Use this index to quickly locate the relevant knowledge for a given task.

---

## 📚 Core Architecture & Patterns

| Skill File | Purpose | Read When |
|------------|---------|-----------|
| `booknow_architecture.md` | Overall platform architecture, multi‑tenancy, tech stack, directory structure, key workflows. | Starting any task; understanding the big picture. |
| `ui_and_frontend_patterns.md` | Inertia + React + Shadcn UI patterns, layouts, form handling, RTL, navigation. | Building any UI page or component. |
| `testing_guidelines.md` | Unit, feature, and E2E testing patterns; mocking APIs; database transactions; tenancy context. | Writing or modifying tests. |
| `error_handling_and_logging.md` | Standardised error responses (API/web), logging levels, exception handling per service. | Adding error handling or logging. |
| `caching_strategies.md` | Multi‑level caching (shared, private, session); route availability cache; flight schedule cache; cache keys, TTL, invalidation. | Implementing caching for performance. |

---

## ✈️ Flight & Airline Integrations

| Skill File | Purpose | Read When |
|------------|---------|-----------|
| `videcom_airline_integration.md` | Videcom API commands (search, price, book, issue, void, refund, seat selection), response parsing, round‑trip pricing rule, commission, provider factory. | Implementing or debugging flight search, booking, ticketing. |
| `route_availability_cache.md` | Global cache for which airlines operate on which routes; calendar price hints; reducing API calls. | Implementing route caching or calendar hints. |

---

## 🛡️ Insurance Integration

| Skill File | Purpose | Read When |
|------------|---------|-----------|
| `insurance_integration_albaraka.md` | Al Baraka API (Swagger): compulsory, travel, orange products; reference data endpoints; pricing; policy creation; error handling. | Implementing any insurance product (compulsory, travel, orange). |

---

## 💰 Financial & Accounting

| Skill File | Purpose | Read When |
|------------|---------|-----------|
| `financial_process.md` | Wallets (`bavix/laravel-wallet`), orders, order_items, commission calculation, ledger posting (`abivia/ledger`), reconciliation, reporting. | Implementing financial actions (wallet deduction, order creation, ledger, reports). |
| `report_generation.md` | PDF generation for invoices, commission statements, settlement reports; using Laravel PDF packages; queued generation. | Building report downloads or scheduled reports. |
| `subscription_billing.md` | SaaS subscription plans, active user counting, invoice generation, seasonal freeze. | Implementing agency subscription billing. |

---

## 🏨 Hotels & Other Products

| Skill File | Purpose | Read When |
|------------|---------|-----------|
| `hotel_integration.md` | Hotel API (e.g., T3N): search availability, room types, multi‑night pricing, booking, cancellation; integration with order/wallet system. | Implementing hotel booking. |
| `esim_product_integration.md` | eSIM product API (e.g., Airalo): data packages, validity days, QR code generation, activation. | Implementing eSIM sales. |

---

## 🔌 APIs & Integrations

| Skill File | Purpose | Read When |
|------------|---------|-----------|
| `white_label_api.md` | RESTful API for agencies (webstore, mobile app): authentication (OAuth/API keys), rate limiting, tenant scoping, endpoint versioning. | Building external APIs for agencies. |
| `payment_gateway_integration.md` | Payment gateway (Stripe/PayPal) for wallet top‑ups and B2C payments: payment intent, webhooks, deposit to wallet. | Implementing online payments. |

---

## 👥 Agency & Merchant Model

| Skill File | Purpose | Read When |
|------------|---------|-----------|
| `default_agency_and_merchant_model.md` | Current default agency (master agency) feature: central admin toggles, `agency_settings`, commission tracking, settlement. Also outlines future network/merchant model (design only). | Implementing default agency features or understanding future merchant model. |

---

## 🌐 Internationalisation & RTL

| Skill File | Purpose | Read When |
|------------|---------|-----------|
| `multi_language_rtl.md` | Arabic/English support, RTL layout, `react-i18next`, date/number formatting, language switcher. | Adding translations or RTL support. |

---

## 🧪 How to Use This Index

1. Identify the task domain (e.g., “add a new insurance product”, “fix wallet deduction”).
2. Look up the relevant skill file(s) in the table above.
3. Read those skill files before writing or modifying code.
4. If multiple skills are listed, read them in order of relevance.

**Example:**  
Task: “Implement compulsory insurance beneficiary form.”  
Relevant skills: `insurance_integration_albaraka.md` (API details), `ui_and_frontend_patterns.md` (form building), `financial_process.md` (order creation and wallet deduction).  
AI should read all three before implementing.

