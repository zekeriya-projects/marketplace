# AGENTS.md

## Project Overview

This repository contains a multi-tenant SaaS platform for centrally managing e-commerce sales channels and marketplaces.

The platform's first supported channels are:

1. WooCommerce
2. Trendyol

Future channels may include Hepsiburada, N11, Amazon and other marketplaces.

The application MUST be designed so adding a new sales channel does not require changing the core catalog, inventory or order domains.

Working project name: `Marketplace SaaS`.

---

## Product Goal

Allow a merchant to connect one or more sales channels and manage:

- products
- variants
- inventory
- prices
- listings
- orders
- synchronization
- channel connections

from one central panel.

The SaaS database is the canonical source of truth after an item has been imported and accepted into the platform.

---

## MVP Scope

The MVP supports only:

- Multi-tenant organizations
- User authentication
- Organization users and roles
- Central product catalog
- Product variants
- Basic warehouse / inventory tracking
- WooCommerce connection
- WooCommerce product import
- WooCommerce product publishing and automatic catalog synchronization
- WooCommerce stock and price updates
- WooCommerce order import
- Trendyol connection
- Trendyol product/listing publishing
- Trendyol stock and price updates
- Trendyol order import
- Product/listing mapping
- Central orders screen
- Synchronization logs
- Retryable background synchronization jobs

Do NOT implement the following unless a future task explicitly requests them:

- Hepsiburada
- N11
- Amazon
- e-Invoice / e-Archive
- accounting integrations
- cargo integrations
- competitor price monitoring
- AI features
- advanced reporting
- subscription billing
- microservices
- Kubernetes

---

## Technology Stack

Backend:
- Laravel 13
- PHP >= 8.3

Frontend:
- React
- TypeScript
- Inertia.js
- Vite

Database:
- PostgreSQL 18

Infrastructure:
- Docker Compose
- WSL2 development environment
- Nginx
- Redis
- Laravel Horizon

Testing:
- Pest

Production file storage:
- S3-compatible storage

Development file storage:
- local filesystem

---

## Architectural Style

Use a modular monolith.

Do NOT create microservices for the MVP.

Primary domains:

- Identity
- Tenancy
- Catalog
- Inventory
- Orders
- Channels
- Sync

Suggested application structure:

```text
app/
├── Domain/
│   ├── Identity/
│   ├── Tenancy/
│   ├── Catalog/
│   ├── Inventory/
│   ├── Orders/
│   ├── Channels/
│   └── Sync/
├── Integrations/
│   ├── Contracts/
│   ├── WooCommerce/
│   └── Trendyol/
├── Jobs/
├── Events/
├── Listeners/
└── Support/
```

Laravel-native conventions may be used where they improve maintainability.

Do not over-engineer the folder structure purely to satisfy this example.

---

## Multi-Tenancy Rules

The SaaS is multi-tenant.

Use a shared database and shared schema.

Every tenant-owned business record MUST include `tenant_id` unless it is globally shared reference data.

Examples of tenant-owned data:

- products
- variants
- warehouses
- inventory
- orders
- channel accounts
- listings
- sync jobs/logs

Never trust `tenant_id` coming from browser/client input.

Tenant context MUST be derived from the authenticated user's active organization.

Every query touching tenant data must be tenant-scoped.

Cross-tenant access is a critical security defect.

---

## Source of Truth

After data is imported into the platform, the SaaS database becomes the canonical source of truth for:

- product identity
- variant identity
- inventory
- marketplace mapping

External channel IDs must only be stored as mappings.

Do not use WooCommerce or Trendyol IDs as internal primary keys.

Example:

```text
products
  id = internal UUID

channel_listings
  product_variant_id = internal UUID
  channel_account_id = internal UUID
  external_product_id = Trendyol/WooCommerce ID
```

---

## IDs

Prefer UUIDv7 for primary identifiers when practical.

Do not expose predictable sequential business IDs in public URLs if avoidable.

Human-readable order/reference numbers may be separate columns.

---

## Money

Never use floating point values for monetary calculations.

Use integer minor units where possible:

```text
100.00 TRY => 10000
```

Store currency using ISO 4217 codes such as:

```text
TRY
USD
EUR
```

Channel-specific selling prices are allowed.

---

## Product Model

A product is the parent commercial entity.

A variant is the sellable stock keeping unit.

Inventory belongs to variants, not products.

Example:

```text
Product:
Nike Air Max

Variants:
Nike Air Max / Black / 42 / SKU:NK-BLK-42
Nike Air Max / Black / 43 / SKU:NK-BLK-43
```

SKU should be unique within a tenant when present.

Barcode should be indexed.

---

## Inventory Model

Do NOT use only a `products.stock` column.

Use:

- warehouses
- inventory_items
- inventory_movements

Inventory is maintained per variant per warehouse.

Every stock-changing business operation should create an inventory movement.

Examples:

- manual_adjustment
- sale
- cancellation
- return
- import
- correction

Inventory mutations must be transactional.

Avoid stock values below zero unless explicitly enabled by a later business rule.

---

## Orders

External marketplace orders are imported into central orders.

Persist:

- channel account
- external order ID
- external order number
- status
- customer/shipping snapshot
- currency
- totals
- items
- timestamps from source platform

Order ingestion MUST be idempotent.

Importing the same external order twice must not create duplicate orders.

Use a unique constraint similar to:

```text
(channel_account_id, external_order_id)
```

Order items must preserve external product/SKU identifiers even if product mapping is missing.

---

## Channel Architecture

All channels must implement contracts instead of leaking marketplace-specific logic into core domains.

Baseline contract concept:

```php
interface ChannelConnector
{
    public function testConnection(): ConnectionResult;

    public function pullProducts(ProductPullRequest $request): ProductPullResult;

    public function pushProduct(Product $product): SyncResult;

    public function updateInventory(ProductVariant $variant): SyncResult;

    public function updatePrice(ProductVariant $variant): SyncResult;

    public function pullOrders(OrderPullRequest $request): OrderPullResult;
}
```

The concrete signature may evolve, but the dependency direction MUST remain:

```text
Core Domain -> Contracts <- Channel Adapter
```

Core domain code must not directly call Trendyol-specific or WooCommerce-specific HTTP clients.

---

## Integration Layout

Example:

```text
app/Integrations/
├── Contracts/
│   ├── ChannelConnector.php
│   ├── DTO/
│   └── Results/
├── WooCommerce/
│   ├── WooCommerceConnector.php
│   ├── WooCommerceClient.php
│   ├── WooCommerceMapper.php
│   └── DTO/
└── Trendyol/
    ├── TrendyolConnector.php
    ├── TrendyolClient.php
    ├── TrendyolMapper.php
    └── DTO/
```

HTTP request/response mapping must remain inside integration modules.

Do not persist raw API payloads as the main domain model.

Raw payloads may optionally be stored in sync/debug logs with sensitive fields removed.

---

## Credentials

Marketplace credentials are sensitive.

Rules:

- Encrypt credentials at rest.
- Never write secrets to logs.
- Never return secrets to frontend responses after initial save.
- Mask sensitive values in the UI.
- Never commit real credentials.
- `.env` files must not be committed.

---

## Queue Rules

External API synchronization must run through queues unless there is a strong reason not to.

Use Redis + Laravel Horizon.

Examples:

- ImportWooCommerceProductsJob
- PullTrendyolOrdersJob
- SyncVariantInventoryJob
- SyncVariantPriceJob
- PublishTrendyolListingJob

Do NOT perform large channel synchronization loops inside controllers.

Controllers should validate intent, dispatch work, and return quickly.

Every network job should define:

- timeout
- retry count
- exponential or appropriate backoff
- error handling
- tenant context
- channel account context

Jobs should be idempotent whenever possible.

---

## Rate Limits

Assume every external channel has API rate limits.

Do not hard-code undocumented rate limits into domain logic.

Rate limit configuration should live per connector / channel.

Queue architecture must support throttling.

HTTP 429 responses should be retried according to provider guidance.

---

## Synchronization

Every synchronization should be observable.

Persist a synchronization record containing at least:

- tenant_id
- channel_account_id
- operation
- entity_type
- entity_id
- status
- attempt
- started_at
- finished_at
- error_code
- safe_error_message

Suggested statuses:

- pending
- running
- succeeded
- failed
- skipped

Never expose secrets in sync error messages.

---

## Events

Use domain/application events for important internal changes where it improves decoupling.

Example:

```text
Marketplace order imported
        ↓
OrderCreated
        ↓
Inventory allocation
        ↓
InventoryChanged
        ↓
Sync inventory to connected channels
```

Avoid creating events for trivial CRUD changes with no downstream behavior.

---

## Database Rules

- PostgreSQL only.
- Use foreign keys.
- Use unique constraints to enforce idempotency and mappings.
- Index tenant-scoped search columns.
- Prefer JSONB only for data that is naturally semi-structured.
- Do not store important queryable business fields only in JSON.
- All migrations must support clean migration from an empty database.

---

## API / Controllers

Controllers must remain thin.

Controllers should not contain:

- marketplace HTTP calls
- inventory algorithms
- pricing calculations
- large synchronization loops

Use application/domain services and jobs.

Validate every external input.

Use authorization policies for protected resources.

---

## Frontend

Frontend stack:

- React
- TypeScript
- Inertia.js

MVP screens:

- Login
- Dashboard
- Products
- Product detail
- Inventory
- Orders
- Order detail
- Channels
- Channel account setup
- Listing status
- Synchronization logs
- Organization settings

Prefer simple business UI over elaborate animations.

Tables must support server-side pagination when data can become large.

---

## Coding Standards

Use:

- strict typing where practical
- typed DTOs for integration boundaries
- PHP enums for finite internal states
- Laravel Form Requests for validation
- Policies for authorization
- service/container binding for connector implementations
- database transactions around business-critical writes

Avoid:

- giant service classes
- static helper sprawl
- hidden global tenant state that cannot be tested
- raw SQL unless justified
- business logic inside Blade/React components
- direct `env()` calls outside config files

Follow Laravel conventions before inventing custom abstractions.

---

## Tests

Every meaningful feature must include tests.

Minimum expectations:

- tenant isolation tests
- authorization tests
- inventory mutation tests
- idempotent order import tests
- product mapping tests
- connector mapping tests
- job retry/failure tests where relevant

External channel API tests must use fakes/mocks.

CI tests must never call real marketplace APIs.

---

## Docker / WSL2

Development target is WSL2 + Docker Compose.

Keep the project inside the Linux filesystem, not `/mnt/c`, for better container filesystem performance.

Expected services:

```text
nginx
app
postgres
redis
horizon
scheduler
```

Optional development-only services may be added when justified.

Application commands should preferably be executable through Docker Compose.

---

## Git Rules

Use small, focused commits.

Suggested prefixes:

```text
chore:
feat:
fix:
refactor:
test:
docs:
```

Do not combine unrelated architectural changes into one commit.

---

## Codex Working Rules

Before implementing any feature:

1. Read `AGENTS.md`.
2. Read relevant files in `/docs`.
3. Inspect the existing implementation.
4. Produce the smallest coherent change.
5. Add/update tests.
6. Run relevant tests.
7. Run formatting/static checks configured by the repository.
8. Report changed files and test results.

Do not silently change architecture.

If a requested feature conflicts with documented architecture, point out the conflict before implementing it.

Do not introduce a new major dependency unless it is clearly necessary.

Do not implement future roadmap features opportunistically.

Do not remove existing behavior just to simplify a new feature.

---

## Definition of Done

A task is complete only when:

- implementation is finished
- validation is present
- authorization is considered
- tenant isolation is preserved
- tests are added/updated
- tests pass
- secrets are not exposed
- migrations run successfully when applicable
- relevant documentation is updated
