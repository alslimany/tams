# L2 Travel eSIM — API mock responses

Mock request/response payloads for the three core L2 whitelabel endpoints used by `App\Services\ESim\Providers\L2Provider`.

**Base URL:** `https://l2travelesim.com` (configurable per tenant)

**Auth headers (outbound API calls):**

| Header | Value |
|--------|-------|
| `x-api-key` | Tenant API key |
| `clientSecret` | Tenant client secret |
| `Content-Type` | `application/json` |
| `Accept` | `application/json` |

---

## 1. Catalogue

`POST /api/whitelabel/v2/catalogue`

### Request

Empty body returns all bundles. Filter by ISO country:

```json
{ "countries": "LY" }
```

See [`requests/catalogue.json`](requests/catalogue.json).

### Response `200`

See [`responses/catalogue.json`](responses/catalogue.json).

| Field | Maps to `ESimPackage` |
|-------|----------------------|
| `bundles[].name` | `id` |
| `bundles[].description` | `name` (fallback: `name`) |
| `bundles[].countries[0].iso` | `country` |
| `bundles[].dataAmount` | `data_mb` (MB; `0` when `unlimited: true`) |
| `bundles[].duration` | `validity_days` |
| `bundles[].price` | `price` (USD) |
| `bundles[].speed` | `speeds` |
| `bundles[].countries` | `countries` |

---

## 2. Process order

`POST /api/whitelabel/v2/processOrders`

### Request — new eSIM

```json
{ "item": "esim_1GB_30D_LY_U" }
```

See [`requests/process-order-new-esim.json`](requests/process-order-new-esim.json).

### Request — top-up existing eSIM

```json
{
  "item": "esim_1GB_30D_LY_U",
  "iccid": "8944538532008160222"
}
```

See [`requests/process-order-topup.json`](requests/process-order-topup.json).

### Response `200` — assigned immediately

See [`responses/process-order-assigned.json`](responses/process-order-assigned.json).

| Field | Maps to `ESimOrderResult` |
|-------|---------------------------|
| `orderReference` | `order_id` |
| `order[0].esims[0].iccid` | `iccid` |
| `order[0].esims[0].matchingId` | `activation_code` |
| `order[0].esims[0].smdpAddress` | `smdp_address` |
| `assigned: true` | `status: assigned` |

**LPA QR string:** `LPA:1${smdpAddress}$${matchingId}`  
Example: `LPA:1$rsp-3104.idemia.io$4I93G-KIS04-H5UUV-VR30Z`

### Response `200` — valid but not yet assigned

Provisioning can take up to ~10 minutes. Poll `POST /api/whitelabel/v2/orders/details` with `orderId`.

See [`responses/process-order-pending.json`](responses/process-order-pending.json).

| Field | Maps to `ESimOrderResult` |
|-------|---------------------------|
| `assigned: false`, `valid: true` | `status: pending` |
| `assigned: false`, `valid: false` | `status: processing` |

---

## 3. Callback (inbound webhook)

L2 posts usage/lifecycle events to a URL configured in the L2 portal (not called by TAMS today).

`POST https://your-tenant.example/webhooks/l2-esim`

### Headers

| Header | Description |
|--------|-------------|
| `Content-Type` | `application/json` |
| `X-HMAC-Signature` | Base64 HMAC-SHA256 of the **raw** request body, keyed with your API key (V3 callbacks) |

Your endpoint should return `200` within 5 seconds. L2 retries on non-2xx.

### Payload — 50% data used

See [`callbacks/utilisation-50-percent.json`](callbacks/utilisation-50-percent.json).

### Payload — 80% data used

See [`callbacks/utilisation-80-percent.json`](callbacks/utilisation-80-percent.json).

### Payload — 100% data used (depleted)

See [`callbacks/utilisation-100-percent.json`](callbacks/utilisation-100-percent.json).

| Field | Notes |
|-------|-------|
| `iccid` | Target eSIM |
| `alertType` | `Utilisation` at 1% / 50% / 80% / 100% thresholds |
| `bundle.initialQuantity` | Bytes at assignment |
| `bundle.remainingQuantity` | Bytes left |
| `bundle.reference` | Assignment reference for status lookups |

---

## Using in tests (`Http::fake`)

```php
Http::fake([
    'https://l2travelesim.com/api/whitelabel/v2/catalogue' => Http::response(
        json_decode(file_get_contents(base_path('docs/api/mocks/l2-esim/responses/catalogue.json')), true),
        200,
    ),
    'https://l2travelesim.com/api/whitelabel/v2/processOrders' => Http::response(
        json_decode(file_get_contents(base_path('docs/api/mocks/l2-esim/responses/process-order-assigned.json')), true),
        200,
    ),
]);
```
