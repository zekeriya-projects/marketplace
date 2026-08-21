# ROADMAP.md

## Development Strategy

Build vertical foundations first, then integrations.

Do not begin by implementing every marketplace endpoint.

Each phase should end with working tests and a usable increment.

---

# Phase 0 — Repository & Development Environment

Goal: create a reproducible local development environment.

Deliverables:

- Laravel 13 application
- React + TypeScript + Inertia.js
- Docker Compose
- Nginx
- PHP application container
- PostgreSQL 18
- Redis
- Horizon worker
- scheduler process
- `.env.example`
- Pest
- base CI-ready test command
- health/readiness sanity checks where useful

Acceptance:

```text
docker compose up -d
```

starts the stack.

The app opens locally.

Database migrations work.

Redis works.

A sample queued job is processed.

Tests pass.

Commit suggestion:

```text
chore: initialize application and docker environment
```

---

# Phase 1 — Tenancy & Authentication

Goal: secure multi-tenant foundation.

Deliverables:

- users
- tenants
- tenant memberships
- active tenant context
- roles: owner/admin/operator/viewer
- login/logout
- tenant-aware authorization
- tenant isolation tests

Acceptance:

A user from Tenant A cannot read or mutate Tenant B resources.

Commit suggestion:

```text
feat: add tenant authentication foundation
```

---

# Phase 2 — Catalog

Goal: canonical product model.

Deliverables:

- products
- variants
- SKU
- barcode
- base price
- product list
- product detail
- server-side search/pagination
- catalog policies/tests

Acceptance:

Tenant can create/read/update its own products and variants.

Commit suggestion:

```text
feat: add central product catalog
```

---

# Phase 3 — Inventory

Goal: reliable central stock model.

Deliverables:

- warehouses
- inventory items
- inventory movements
- manual adjustment
- stock transaction locking
- stock history UI
- inventory tests

Acceptance:

Two concurrent changes cannot silently corrupt quantity.

Every successful adjustment has a movement.

Commit suggestion:

```text
feat: add warehouse inventory ledger
```

---

# Phase 4 — Channel Foundation

Goal: marketplace-independent integration architecture.

Deliverables:

- channels
- channel accounts
- encrypted credentials
- channel listings
- connector contract
- integration DTO/result conventions
- sync operations/logs
- base queued sync infrastructure

Seed/reference channels:

- WooCommerce
- Trendyol

Acceptance:

Core catalog/inventory does not depend on WooCommerce or Trendyol classes.

Commit suggestion:

```text
feat: add channel integration foundation
```

---

# Phase 5 — WooCommerce Connection

Goal: connect a WooCommerce store.

Deliverables:

- create channel account
- encrypted API credentials
- connection test
- credential update
- safe UI masking
- HTTP client foundation
- fake-based connector tests

Acceptance:

User can connect a store without exposing stored secrets.

Commit suggestion:

```text
feat: add woocommerce account connection
```

---

# Phase 6 — WooCommerce Catalog Import

Goal: import WooCommerce products into canonical catalog.

Deliverables:

- paginated async import
- mapping
- variant normalization
- repeated import idempotency
- import sync log
- progress/status visibility
- failures do not abort unrelated product pages when avoidable

Acceptance:

Importing the same catalog twice does not create duplicate mapped products/variants.

Commit suggestion:

```text
feat: import woocommerce catalog
```

---

# Phase 7 — WooCommerce Inventory & Price Sync

Goal: push central data to WooCommerce.

Deliverables:

- inventory sync job
- price sync job
- retry/backoff
- sync result logging
- manual retry UI for failed sync

Acceptance:

Changing stock centrally can update the mapped WooCommerce variant asynchronously.

Commit suggestion:

```text
feat: sync woocommerce inventory and prices
```

---

# Phase 8 — Central Orders

Goal: create provider-independent order model.

Deliverables:

- orders
- order items
- normalized statuses
- order list/detail
- channel filter
- idempotent ingestion service
- mapping status

Acceptance:

Repeated import of same external order creates one internal order.

Commit suggestion:

```text
feat: add central order domain
```

---

# Phase 9 — WooCommerce Orders

Goal: import WooCommerce orders.

Deliverables:

- scheduled polling and/or supported webhook path
- order mapping
- dedupe
- mapped-item inventory mutations
- unmapped item handling
- order import logs

Acceptance:

A mapped new WooCommerce order reduces central inventory exactly once.

Commit suggestion:

```text
feat: import woocommerce orders
```

---

# Phase 10 — Trendyol Connection

Goal: connect merchant's Trendyol seller account.

Deliverables:

- encrypted seller credentials
- connection test
- Trendyol HTTP client
- rate-limit/error translation
- fake-based tests

Acceptance:

Connection is testable and failures are safely explained.

Commit suggestion:

```text
feat: add trendyol account connection
```

---

# Phase 11 — Trendyol Listings

Goal: connect canonical catalog with Trendyol.

Deliverables:

- category/attribute integration required by publishing flow
- listing mapping
- publish product/listing job
- rejected listing errors
- listing status UI

Do not build a universal PIM.

Only implement category/attribute functionality required to successfully publish the MVP product flow.

Acceptance:

An eligible internal product can be published/mapped and gets a persistent channel listing mapping.

Commit suggestion:

```text
feat: add trendyol listing synchronization
```

---

# Phase 12 — Trendyol Inventory & Price

Goal: synchronize central inventory/prices.

Deliverables:

- stock job
- price job
- throttling
- retry/backoff
- sync logs

Acceptance:

Central stock change can propagate to Trendyol without blocking the user request.

Commit suggestion:

```text
feat: sync trendyol inventory and prices
```

---

# Phase 13 — Trendyol Orders

Goal: complete the first end-to-end marketplace loop.

Deliverables:

- scheduled order pulling
- cursor/window handling
- order normalization
- item mapping
- idempotent inventory mutation
- downstream stock sync to WooCommerce
- missing mapping warnings

Acceptance scenario:

```text
Trendyol order received
        ↓
Central order created once
        ↓
Mapped variant inventory -1
        ↓
Inventory movement recorded
        ↓
WooCommerce stock sync queued
        ↓
WooCommerce updated
        ↓
Every operation visible in logs
```

Commit suggestion:

```text
feat: import trendyol orders and sync inventory
```

---

# Phase 14 — MVP Hardening

Goal: make the MVP pilot-ready.

Deliverables:

- authorization audit
- tenant isolation audit
- integration credential audit
- failed job flows
- rate-limit tests
- pagination review
- database indexes
- backup strategy documentation
- production environment docs
- structured operational logging
- basic monitoring plan

Acceptance:

Pilot merchants can be onboarded without developer intervention for routine connection/sync failures.

Commit suggestion:

```text
chore: harden mvp for pilot release
```

---

# Post-MVP Candidate Roadmap

## Completed merchant-requested extension — WooCommerce catalog publishing

- Publish centrally created simple and variable products to active WooCommerce accounts through queues.
- Persist product and variation mappings before subsequent inventory and price synchronization.
- Re-publish canonical catalog changes idempotently without creating duplicate remote products.
- Keep HTTPS Basic Auth and local HTTP OAuth 1.0a behavior inside the WooCommerce adapter.

Completed merchant-requested extension (2026-08-11):

- hierarchical catalog categories
- catalog brands
- reusable variant templates and central variant list
- queued Excel/CSV/XML catalog import
- downloadable Excel import templates

Only after pilot feedback:

1. Hepsiburada
2. N11
3. marketplace-specific price rules
4. multiple warehouses/depot allocation improvements
5. returns
6. shipping integrations
7. e-invoice/e-archive
8. subscription billing
9. advanced reports
10. supplier/XML integrations
11. Amazon

Prioritize based on paying customer demand, not architecture curiosity.

---

# First Codex Task

After placing all documentation in the repository, give Codex this task:

```text
Read AGENTS.md and every file under docs/ before making changes.

Implement Phase 0 from docs/ROADMAP.md only.

Create a Laravel 13 application using PHP 8.3+ with React, TypeScript and Inertia.js.

Create a Docker Compose development environment intended for WSL2 with:
- nginx
- app/php-fpm
- PostgreSQL 18
- Redis
- Laravel Horizon
- Laravel scheduler

Requirements:
- The repository must run from the WSL Linux filesystem.
- Do not implement marketplace business features yet.
- Add Pest and a minimal test proving the application boots.
- Add a small queue smoke test or development verification demonstrating a Redis-backed job can be processed.
- Configure Laravel to use PostgreSQL and Redis through environment variables.
- Add a complete .env.example with no real secrets.
- Add healthchecks/restart configuration where reasonable.
- Keep Docker files maintainable and production-conscious, but optimize for local development.
- Do not add unnecessary services.
- Do not introduce microservices.
- Follow AGENTS.md architecture and security rules.
- Run migrations and tests after implementation.
- At the end, report:
  1. files created/changed,
  2. commands used to start the project,
  3. test results,
  4. any deliberate deviations from the documentation.

Do not start Phase 1.
```
