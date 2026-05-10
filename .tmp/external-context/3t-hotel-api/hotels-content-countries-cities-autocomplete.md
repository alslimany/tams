---
source: Postman Documenter API
library: 3T Hôtel API
package: 3t-hotel-api
topic: hotels-content countries/cities and autocomplete response shape
fetched: 2026-05-06T00:00:00Z
official_docs: https://documenter.getpostman.com/view/5722171/2sB34hHLYX
api_version: "Hotel API version 3.6 : 13/02/2026"
---

# 3T Hôtel API — relevant endpoint details

Base URL: `https://btob.3t.tn`

Authentication headers shown in the Postman examples:

- `Api-key: <api key>`
- `Login: <login>`
- `Password: <password>`

## Static Data: countries

### Request

```http
GET https://btob.3t.tn/hotels-content?method=getCountries
Api-key: <api key>
Login: <login>
Password: <password>
```

Query parameters:

- `method` = `getCountries`

Body: none.

### Response

HTTP `200`, JSON array of country objects.

Country fields:

- `id` number
- `name` string
- `currency` string
- `prefix` number
- `codeAlpha2` string; use this as `countryId` for `getCities`

Example:

```json
[
  { "id": 1, "name": "Afghanistan", "currency": "AFN", "prefix": 93, "codeAlpha2": "AF" },
  { "id": 2, "name": "Albania", "currency": "ALL", "prefix": 355, "codeAlpha2": "AL" },
  { "id": 75, "name": "France", "currency": "EUR", "prefix": 33, "codeAlpha2": "FR" }
]
```

## Static Data: cities

### Request

```http
GET https://btob.3t.tn/hotels-content?method=getCities&countryId=FR
Api-key: <api key>
Login: <login>
Password: <password>
```

Query parameters:

- `method` = `getCities`
- `countryId` = country alpha-2 code, e.g. `FR`, from `getCountries[].codeAlpha2`

Body: none.

### Response

HTTP `200`, JSON array of city objects.

City fields:

- `type` string; sample value `City`
- `id` number; city id usable when a booking/search request needs a destination city id
- `name` string
- `latitude` string
- `longitude` string
- `country` string

Example:

```json
[
  {
    "type": "City",
    "id": 20,
    "name": "ANNECY",
    "latitude": "45.916000000000",
    "longitude": "6.133000000000",
    "country": "France"
  },
  {
    "type": "City",
    "id": 3349,
    "name": "Paris",
    "latitude": "48.856700000000",
    "longitude": "2.352200000000",
    "country": "France"
  }
]
```

## Booking Process: autocomplete

### Request

```http
POST https://btob.3t.tn/hotels-api?method=autocomplete
Api-key: <api key>
Login: <login>
Password: <password>
Content-Type: application/json

{
  "termSearch": "paris"
}
```

Query parameters:

- `method` = `autocomplete`

JSON body:

- `termSearch` string; search text.

### Response

HTTP `200`, JSON object.

Top-level fields:

- `method` string; called method
- `response` array
- `error` boolean
- `errorCode` number
- `msg` string; error message if `error=true`, sample success value `Ok`
- `requestHost` string; request host IP
- `timing_seconds` number

`response[]` fields from the actual sample:

- `id` number
- `label` string; title for hotel or city
- `country` string
- `category` string; docs describe values as `HOTEL` or `VILLE`

Example:

```json
{
  "method": "autocomplete",
  "response": [
    { "id": 1553, "label": "Disneyland - paris", "country": "FRANCE", "category": "VILLE" },
    { "id": 3349, "label": "Paris", "country": "FRANCE", "category": "VILLE" },
    { "id": 3965, "label": "Paris surroundings", "country": "FRANCE", "category": "VILLE" }
  ],
  "error": false,
  "errorCode": 200,
  "msg": "Ok",
  "requestHost": "139.99.149.181",
  "timing_seconds": 0.36699795722961
}
```

## Resolving a city id when autocomplete gives only a label

The current Postman sample includes `response[].id` for autocomplete city results (`category: "VILLE"`). If an integration only retained/received `label`, resolve it from static data as follows:

1. Call `getCountries`; match the autocomplete `country` to `getCountries[].name` case-insensitively, then take `codeAlpha2`.
   - Example: autocomplete `country: "FRANCE"` => countries `name: "France"`, `codeAlpha2: "FR"`.
2. Call `getCities&countryId=<codeAlpha2>`.
3. Match autocomplete `label` to `getCities[].name` case-insensitively.
4. Use the matched `getCities[].id`.

Example: `label: "Paris"`, `country: "FRANCE"` => `countryId=FR`; `getCities` contains `{ "id": 3349, "name": "Paris", ... }`; resolved city id is `3349`.
