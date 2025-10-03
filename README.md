# Evy API

Slim 4 based REST API that proxies requests to the Evydencia CRM and exposes a consistent, observable interface with API Key protection and rate limiting. The current surface area covers health monitoring, order workflows, sold-items reporting and campaign scheduling.

## Table of contents

- [Features](#features)
- [Project layout](#project-layout)
- [Getting started](#getting-started)
  - [Requirements](#requirements)
  - [Installation](#installation)
  - [Configuration](#configuration)
  - [Run locally](#run-locally)
- [Response contract](#response-contract)
  - [Success envelope](#success-envelope)
  - [Error envelope](#error-envelope)
  - [Pagination and filtering DSL](#pagination-and-filtering-dsl)
- [CRM integration details](#crm-integration-details)
- [HTTP endpoints](#http-endpoints)
  - [/health](#health)
  - [/v1/orders/search](#v1orderssearch)
  - [/v1/orders/{uuid}](#v1ordersuuid)
  - [/v1/orders/{uuid}/status](#v1ordersuuidstatus)
  - [/v1/reports/sold-items](#v1reportssold-items)
  - [/v1/campaigns/schedule](#v1campaignsschedule)
- [Logging & observability](#logging--observability)
- [Rate limiting](#rate-limiting)
- [Next steps](#next-steps)

## Features

- Slim 4 + PHP-DI bootstrap with explicit container configuration.
- Uniform response envelope (`success`, `data`, `meta`, `links`, `trace_id`).
- API Key authentication (header `X-API-Key`) for every `/v1/**` route.
- Consistent pagination, sorting and filtering DSL (aliases translated to CRM query params).
- Guzzle HTTP client wrapper (`EvydenciaApiClient`) with 30s timeout, automatic header injection and structured error handling.
- Request logging middleware (method, path, status, duration, trace_id) with Monolog.
- Redis-backed rate limiting middleware (per IP + route).

## Project layout

```
app/
  Actions/                # HTTP handlers grouped by domain (Orders, Reports, Campaigns, Health)
  Application/            # DTOs, services (order/report/campaign) and QueryMapper support
  Domain/                 # Exceptions and repository contracts (orders_map is optional, future work)
  Infrastructure/         # HTTP client, logging factory, rate limiter, persistence adapters, etc.
  Middleware/             # API key, rate limiting and request logging middlewares
  Settings/               # Typed access to configuration arrays
config/
  settings.php            # Reads .env and builds the settings array
  dependencies.php        # PHP-DI bindings
  middleware.php          # Registers global middlewares and error handler
  routes.php              # Route definitions
public/
  index.php               # Front controller
var/logs/                 # Runtime logs (gitignored)
README.md                 # This file
```

## Getting started

### Requirements

- PHP 8.3+
- Composer 2.8+
- Redis (optional, required only if you want rate limiting)
- MySQL (optional; currently only used for the optional `orders_map` repository)

### Installation

```bash
composer install
```

### Configuration

1. Copy `.env.example` to `.env`.
2. Set the mandatory values:
   - `APP_API_KEY`: API key that clients must send in `X-API-Key`.
   - `CRM_BASE_URL`: defaults to `https://evydencia.com/api`.
   - `CRM_TOKEN`: Evydencia access token (plain value, no `Bearer`).
3. Optional adjustments:
   - `LOG_*` to control Monolog channel, path and level.
   - `REDIS_*` if rate limiting should use an external Redis.
   - `DB_*` if you plan to persist data locally (future endpoints).

### Run locally

There are two common options:

```bash
# PHP built-in server (default port 8080)
composer start

# or explicitly
php -S 127.0.0.1:8080 -t public
```

With Laragon/Apache, point the virtual host root to `public/` and ensure URL rewriting is enabled (`public/.htaccess`).

## Response contract

### Success envelope

```json
{
  "success": true,
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 50,
    "total": null,
    "count": 0,
    "total_pages": null,
    "source": "crm",
    "elapsed_ms": 5
  },
  "links": {
    "self": "http://localhost:8080/v1/orders/search?page=1",
    "next": "http://localhost:8080/v1/orders/search?page=2",
    "prev": null
  },
  "trace_id": "f0e1d2c3b4a59687"
}
```

- `meta.source` indicates the upstream (`crm` for Evydencia, `api` for local data).
- `meta.count` is the number of items returned in `data`.
- `links` follow the pagination DSL; `next`/`prev` are omitted when unavailable.

### Error envelope

```json
{
  "success": false,
  "error": {
    "code": "unprocessable_entity",
    "message": "Parametros invalidos",
    "errors": [
      { "field": "page", "message": "deve ser maior ou igual a 1" }
    ]
  },
  "trace_id": "d18ed62ebc6345b5"
}
```

Error codes used today: `unauthorized`, `too_many_requests`, `bad_gateway`, `internal_error`, `not_found`, `conflict`, `unprocessable_entity`.

### Pagination and filtering DSL

| Concept            | Query parameters                                                                | Notes                                                                                                     |
|--------------------|----------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------|
| Pagination         | `page`, `per_page` (defaults: 1 / 50, max per_page = 200)                        | `page[number]` / `page[size]` aliases also accepted.                                                      |
| Sorting            | `sort=field,-other`                                                              | `-` indicates descending. Order service converts to `field:asc|desc` for the CRM.                         |
| Basic filters      | `filter[field]=value`                                                            | Mapped via `QueryMapper`; aliases listed below.                                                           |
| `IN` filters       | `filter[field][in]=a,b,c`                                                        | Converted to comma-separated string.                                                                      |
| Range filters      | `filter[field][gte|lte]=YYYY-MM-DD` (or `YYYY-MM-DD HH:MM:SS` when applicable)    | Mapped to `order[created-start]`, `order[created-end]`, etc.                                              |
| Like filters       | `filter[field][like]=text`                                                       | Wrapped with `%text%` before sending to the CRM.                                                          |
| Projection         | `fields[orders]=uuid,status,customer.name`                                      | Optional; applies to the in-memory result (after CRM response).                                          |
| Fetch all pages    | `fetch=all` (alias `all=true`)                                                   | Iterates `links.next` until exhaustion or 20 pages (safety cap).                                          |
| Pass-through raw   | `order[status]=...`, `product[slug]=...`                                        | Already in the CRM format; forwarded untouched.                                                           |

**Alias mapping (Orders):**

```
status            -> order[status]
created_start     -> order[created-start]
created_end       -> order[created-end]
session_start     -> order[session-start]
session_end       -> order[session-end]
selection_start   -> order[selection-start]
selection_end     -> order[selection-end]
customer_uuid     -> customer[uuid]
customer_email    -> customer[email]
customer_whatsapp -> customer[whatsapp]
customer_name     -> customer[name]
product_uuid      -> product[uuid]
product_name      -> product[name]
product_slug      -> product[slug]
product_ref       -> product[reference]
```

Sold items and campaign endpoints use similar aliases:

```
Sold items aliases:
  item_name -> item[name]
  item_slug -> item[slug]
  item_ref  -> item[ref]
  created_at[gte|lte] -> order[created-start|end]

Campaign schedule aliases:
  campaign_id   -> campaign[id]
  contact_phone -> contacts[phone]
```

## CRM integration details

- Base URI: `CRM_BASE_URL` (default `https://evydencia.com/api`).
- Headers sent on every call: `Accept: application/json`, `Authorization: {CRM_TOKEN}`, `Trace-Id`.
- Guzzle client: 30s timeout, `http_errors=false`.
- Wrapper methods: `get`, `post`, `put`, plus convenience helpers (`searchOrders`, `fetchOrderDetail`, etc.).
- Error handling:
  - Network/timeout -> throws `CrmUnavailableException` (mapped to 502 Bad Gateway).
  - HTTP >= 400 -> throws `CrmRequestException` (logged with status + keys present in body).
  - Non-JSON body is attached to the `raw` key.

## HTTP endpoints

### /health

| Method | Description              | Auth | Query/body | Response source |
|--------|--------------------------|------|------------|-----------------|
| GET    | Liveness/health check.   | No   | N/A        | `meta.source=api`

**Response example**

```json
{
  "success": true,
  "data": {
    "status": "ok",
    "timestamp": "2025-10-02T17:10:21+00:00"
  },
  "meta": {
    "page": 1,
    "per_page": 1,
    "total": 1,
    "source": "api"
  },
  "links": {
    "self": "http://localhost:8080/health",
    "next": null,
    "prev": null
  },
  "trace_id": "1ae52f64d8d14c88"
}
```

### /v1/orders/search

| Method | Description                              | Auth | Notes |
|--------|------------------------------------------|------|-------|
| GET    | CRM proxy that searches for orders.      | Yes  | Supports full pagination/filter DSL, projection, `fetch=all`.

**Key query parameters**

- `page`, `per_page`, `sort`, `fields[orders]`
- `filter` aliases listed earlier (`status`, `created_start`, etc.)
- Raw pass-through (`order[status]=...`)
- `fetch=all` to iterate up to 20 pages (aggregated locally)

**Example**

```bash
curl "http://localhost:8080/v1/orders/search?status=payment_confirmed&product_slug=natal&page=1&per_page=50" \
  -H "X-API-Key: <APP_API_KEY>"
```

### /v1/orders/{uuid}

| Method | Description                              | Auth | Body |
|--------|------------------------------------------|------|------|
| GET    | Fetches order detail (`/orders/{uuid}/detail`). | Yes | None |

Adds `local_map` if the optional local repository returns data. Returns `source=crm`.

### /v1/orders/{uuid}/status

| Method | Description                                    | Auth | Body |
|--------|------------------------------------------------|------|------|
| PUT    | Updates order status via `PUT /order/status`.   | Yes  | `{ "status": "...", "note": "optional" }`

- `status`: required string 2..64 characters.
- `note`: optional string up to 255 characters.
- Sanitises inputs and persists the payload to the optional `orders_map` table if configured.

### /v1/reports/sold-items

| Method | Description                                 | Auth | Notes |
|--------|---------------------------------------------|------|-------|
| GET    | CRM report of sold items.                   | Yes  | Supports aliases `item_name`, `item_slug`, `item_ref`, `created_at[gte|lte]`, plus `fetch=all`.

### /v1/campaigns/schedule

| Method | Description                               | Auth | Notes |
|--------|-------------------------------------------|------|-------|
| GET    | CRM campaign scheduling information.      | Yes  | Filters: `campaign_id`, `contact_phone`, pagination DSL, `fetch=all`.

## Logging & observability

- `RequestLoggingMiddleware` logs one line per request (`method`, `path`, `status`, `duration_ms`, `trace_id`).
- Every response includes the same `Trace-Id` header/value for correlation.
- Errors are converted to the JSON problem response and recorded via Monolog.

## Rate limiting

- Controlled by `RATE_LIMIT_PER_MINUTE` (default 60 req/min) and `rate_limit.window` (default 60s).
- Keys on `rate_limit:{IP_HASH}:{ENDPOINT_HASH}` in Redis.
- When the limit is reached, returns `429 Too Many Requests` with headers `Retry-After`, `X-RateLimit-*`.
- If Redis is not configured, the middleware short-circuits, effectively disabling the limit.

## Next steps

- Implement local MySQL-backed resources (blacklist, scheduled posts) using the existing repository contracts.
- Add automated tests for QueryMapper edge cases, services and middleware behaviour.
- Provide Docker Compose for PHP + Redis + MySQL to ease onboarding.
- Extend logging with structured context (correlation IDs, upstream timings).
