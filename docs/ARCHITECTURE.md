# ARCHITECTURE.md

## 1. Architecture Decision

The MVP uses a modular Laravel monolith.

Reasons:

- fast product iteration
- native queues and scheduler
- straightforward transactional business logic
- simple deployment
- lower operational complexity than microservices
- easy extraction of heavy synchronization workers later if required

Microservices are explicitly deferred.

---

## 2. Runtime Topology

Development:

```text
Windows
└── WSL2
    └── Docker Compose
        ├── nginx
        ├── app (PHP-FPM / Laravel)
        ├── postgres
        ├── redis
        ├── horizon
        └── scheduler
```

Frontend assets are built through the application development environment using Vite.

---

## 3. Main Application Domains

### Identity

Responsibilities:

- authentication
- users
- passwords
- sessions/tokens if needed

### Tenancy

Responsibilities:

- organizations/tenants
- memberships
- roles
- active tenant context
- tenant-scoped authorization

### Catalog

Responsibilities:

- products
- variants
- SKUs
- barcodes
- core product attributes

### Inventory

Responsibilities:

- warehouses
- stock per variant
- movements
- adjustments
- stock mutations

### Orders

Responsibilities:

- normalized orders
- order items
- order status
- idempotent channel ingestion

### Channels

Responsibilities:

- channel definitions
- merchant channel accounts
- listings
- channel mapping

### Sync

Responsibilities:

- queued operations
- sync logs
- retries
- error visibility
- channel rate controls

---

## 4. Dependency Direction

Core business domains must not know how a specific external API works.

Preferred dependency direction:

```text
             ┌────────────────────┐
             │   Core Domains     │
             │ Catalog/Orders/etc │
             └─────────┬──────────┘
                       │
                       v
             ┌────────────────────┐
             │ Integration        │
             │ Contracts / DTOs   │
             └─────────▲──────────┘
                       │
          ┌────────────┴────────────┐
          │                         │
┌─────────┴──────────┐    ┌─────────┴──────────┐
│ WooCommerce Adapter│    │ Trendyol Adapter   │
└────────────────────┘    └────────────────────┘
```

Provider-specific payloads are translated to internal DTOs at the integration boundary.

---

## 5. Request Flow

Typical user operation:

```text
Browser
  ↓
Nginx
  ↓
Laravel Controller
  ↓
Authorization / Validation
  ↓
Application Service
  ↓
Database transaction or queued job
  ↓
Response
```

Large external API operations are dispatched to a queue instead of being executed inline.

---

## 6. Marketplace Sync Flow

Example: stock changed centrally.

```text
Inventory adjustment / imported order
              ↓
       InventoryChanged
              ↓
Determine active channel listings
              ↓
Dispatch one sync job per listing/account
              ↓
        Redis Queue
              ↓
          Horizon
              ↓
 Connector.updateInventory()
              ↓
       External API
              ↓
       Sync result stored
```

Separate jobs per channel/listing make failures isolated and retryable.

---

## 7. Order Pull Flow

Initial MVP may use scheduled polling where provider webhooks are unavailable or insufficient.

```text
Scheduler
   ↓
Dispatch PullOrders job
   ↓
Connector fetches page/window
   ↓
Map external DTO
   ↓
Idempotent order upsert/create
   ↓
Resolve item mappings
   ↓
Inventory transaction
   ↓
Dispatch stock synchronization
```

Store a per-account synchronization cursor/window when the provider supports it.

Never rely solely on "last local order ID" unless the provider guarantees monotonic ordering semantics.

---

## 8. Webhooks

Where WooCommerce or future providers support useful webhooks:

```text
Provider
   ↓
Webhook endpoint
   ↓
Verify authenticity
   ↓
Persist/dedupe event
   ↓
Dispatch processing job
   ↓
Return fast HTTP response
```

Webhook controllers must not perform long operations.

Webhook processing must be idempotent.

---

## 9. Connector Responsibilities

A connector is responsible for:

- authentication headers
- base URL
- provider-specific request formats
- pagination
- provider rate limits
- HTTP error translation
- provider DTO mapping
- external status mapping

A connector is NOT responsible for:

- deciding the tenant
- mutating arbitrary inventory directly
- authorization
- rendering UI
- implementing global business rules

---

## 10. HTTP Client

Create a consistent integration HTTP layer.

Requirements:

- timeouts
- retry-aware exception types
- sanitized logs
- correlation/request IDs when useful
- provider response status capture
- no secret leakage

Do not automatically retry non-idempotent requests unless safe or supported by provider semantics.

---

## 11. Queue Strategy

Suggested queues:

```text
default
imports
orders
inventory-sync
price-sync
listing-sync
```

Initial deployment may use a smaller number of workers, but job types should remain logically separated.

Suggested job metadata:

- tenant_id
- channel_account_id
- entity ID
- sync_operation_id

Do not serialize full large Eloquent model graphs into queued jobs.

Prefer identifiers and reload current data in the worker.

---

## 12. Retry Strategy

Retry policy varies by operation.

Retryable examples:

- timeout
- transient 5xx
- 429 rate limit
- network failure

Usually non-retryable without correction:

- invalid credentials
- invalid product attributes
- missing required category attribute
- rejected barcode
- unauthorized resource

Store normalized error categories.

Suggested:

```text
authentication
validation
rate_limited
network
remote_server
not_found
conflict
unknown
```

---

## 13. Transactions

Use DB transactions for:

- order import + inventory movement creation
- manual stock adjustment
- listing mapping creation when multiple records must stay consistent

Do not hold database transactions open while waiting on marketplace HTTP requests.

Pattern:

```text
DB transaction
   ↓ commit
Dispatch external synchronization job
```

---

## 14. Concurrency

Inventory writes can race.

Use transaction-level locking or another explicit concurrency strategy when changing an inventory item's quantity.

Example conceptual flow:

```text
BEGIN
SELECT inventory_item FOR UPDATE
validate quantity
create inventory_movement
update inventory_item
COMMIT
```

Exact implementation should follow Laravel/PostgreSQL best practices.

---

## 15. Observability

MVP observability:

- Laravel logs
- Horizon dashboard
- synchronization log table
- failed jobs

Log context should include safe identifiers:

```text
tenant_id
channel_account_id
sync_operation_id
job_id
order_id
product_variant_id
```

Do not log secrets or full customer payloads unnecessarily.

---

## 16. Security

Mandatory:

- tenant isolation
- authorization policies
- CSRF protection where applicable
- encrypted channel credentials
- webhook signature/auth verification
- safe mass assignment
- server-side validation
- secrets only in environment/config
- rate limit public/auth endpoints
- no credentials in client-side JS

Sensitive merchant integration credentials should be decryptable only by the application where required to call external APIs.

---

## 17. Scaling Path

Do not pre-optimize, but preserve extraction boundaries.

Possible future scaling sequence:

1. scale Laravel app replicas
2. scale Horizon workers independently
3. split queues by provider/type
4. add dedicated Redis infrastructure
5. optimize PostgreSQL/indexing
6. extract a high-throughput integration worker only when measurements justify it

Do not jump directly to microservices.

---

## 18. Development Environment

Store repository inside WSL Linux filesystem, for example:

```text
~/projects/marketplace-saas
```

Avoid developing from:

```text
/mnt/c/...
```

Docker Desktop should use WSL2 integration.

Typical commands should become project scripts or Makefile targets later, for example:

```bash
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan test
docker compose exec app php artisan horizon:status
```

---

## 19. Initial Infrastructure Acceptance Criteria

Before business features:

- Laravel app loads through Nginx
- PostgreSQL connection works
- Redis connection works
- migrations run
- queue job can be dispatched and processed by Horizon
- scheduler container/process runs
- React/Inertia page loads
- Pest test suite passes
- all services have sane restart behavior
- `.env.example` documents required config
