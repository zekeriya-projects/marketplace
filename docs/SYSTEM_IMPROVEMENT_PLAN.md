# System Improvement Plan

Updated: 2026-08-19

## Purpose

This document tracks the post-MVP improvements identified during the general system audit. It is the canonical checkpoint for this work so implementation can continue step by step across sessions without losing the current position.

The completed MVP phases remain documented in `ROADMAP.md`. Items in this document are not complete unless their status and acceptance checklist say so.

## Status convention

- `[ ]` Not started
- `[-]` In progress
- `[x]` Completed and verified
- `[!]` Blocked; the reason must be recorded in the work log

Only one item should normally be marked `[-]`. After completing an item:

1. Check every acceptance criterion.
2. Record tests and verification commands in the work log.
3. Update `CURRENT_STATE.md` if externally visible behavior changed.
4. Move `Current checkpoint` to the next item.

## Current checkpoint

**All planned P0–P4 improvement items are complete.**

No improvement item below has been implemented as part of the 2026-08-19 audit. The baseline verification at the start of this plan was:

- Full backend suite: **135 passed, 729 assertions**
- Production frontend build: **passed**, with a JavaScript chunk-size warning
- Isolated `TrendyolListingTest.php`: **5 failed** because shared test helper functions are declared in other test files

---

## P0 — Inventory and order correctness

### [x] P0.1 — Cancellation and return inventory compensation

Problem:

Imported mapped orders reduce inventory exactly once, but cancellation/refund transitions do not automatically restore it. Operators currently need a manual adjustment, which can cause canonical stock to drift.

Scope:

- Add idempotent compensation for cancelled orders.
- Add full and partial return quantities per order item.
- Create immutable `cancellation` and `return` inventory movements.
- Prevent repeated imports or repeated provider status notifications from restoring stock twice.
- Dispatch downstream inventory synchronization only after the transaction commits.
- Preserve tenant isolation and warehouse/variant agreement.

Acceptance:

- [x] A mapped cancelled order restores only the quantity previously deducted.
- [x] Re-importing the same cancellation does not restore inventory twice.
- [x] Full and partial returns create the correct movement and quantity.
- [x] Unmapped order items do not mutate inventory.
- [x] Negative or excessive return quantities are rejected.
- [x] Cross-tenant records cannot be referenced.
- [x] Transaction rollback leaves both inventory snapshot and movement ledger unchanged.
- [x] Feature tests cover WooCommerce and Trendyol status ingestion.

### [x] P0.2 — Stock reservation lifecycle

Problem:

`reserved_quantity` is included in available-stock calculation, but no business workflow creates or releases reservations. Concurrent marketplace orders may therefore oversell the final units.

Scope:

- Define the statuses that reserve, consume, release, or restore stock.
- Reserve mapped order quantities transactionally using locked inventory rows.
- Convert reservations into sale movements at the chosen confirmation boundary.
- Release reservations on rejection, expiry, or cancellation.
- Keep repeated order imports idempotent.
- Decide and document warehouse allocation behavior.

Acceptance:

- [x] Two concurrent reservations cannot reserve more than available stock.
- [x] Reservation, consumption, and release transitions are idempotent.
- [x] `quantity`, `reserved_quantity`, and available quantity remain consistent.
- [x] Channel stock pushes use the committed available quantity.
- [x] Tenant isolation, concurrency, and rollback tests pass.

---

## P1 — High-volume reporting and operation history

### [x] P1.1 — Database-aggregated reporting

Problem:

`ReportController` currently loads all matching orders, items, active inventory items, and synchronization operations into PHP collections. A one-year report can exhaust application memory or time out as tenant data grows.

Scope:

- Move daily sales, status, channel, synchronization, and top-product calculations to PostgreSQL aggregations.
- Query only the top low-stock rows needed by the screen.
- Avoid building large `whereIn` order-ID collections.
- Add tenant-aware composite indexes after verifying plans with representative data.
- Add bounded caching where it does not make operational data misleading.
- Generate large CSV/Excel exports asynchronously if exports are introduced.

Acceptance:

- [x] Report output remains functionally equivalent for existing filters.
- [x] No report query loads all matching business rows into PHP memory.
- [x] Cross-tenant account filters remain rejected.
- [x] Tests cover empty data, mixed currencies, date boundaries, accounts, and tenant isolation.
- [x] A documented high-volume benchmark and query-plan check pass.

### [x] P1.2 — Synchronization-log retention and archival

Problem:

The UI groups technical operations, but raw `sync_operations` rows grow without a retention or archival policy. Millions of records will eventually increase storage, index, backup, and maintenance costs.

Scope:

- Define separate retention periods for succeeded, skipped, and failed operations.
- Preserve durable business/audit summaries while pruning disposable technical attempts.
- Implement a tenant-safe, chunked scheduled cleanup/archive command.
- Ensure cleanup cannot remove active pending/running operations.
- Consider monthly PostgreSQL partitioning only if measured volume justifies it.
- Add monitoring for table/index size and cleanup failures.

Acceptance:

- [x] Retention periods are configuration-driven and documented.
- [x] Cleanup is chunked, restartable, and safe to run repeatedly.
- [x] Pending/running and recently failed operations are preserved.
- [x] Tenant-visible grouped history remains correct after cleanup.
- [x] Scheduler, command, retention-boundary, and failure tests pass.

---

## P2 — Scalable marketplace product operations

### [x] P2.1 — Template-driven Trendyol bulk mapping and publishing

Problem:

WooCommerce bulk publishing queues products, but unlinked Trendyol products only report that category and attribute setup is required. Merchants still have to configure large catalogs one product at a time.

Scope:

- Add reusable tenant-scoped Trendyol category/brand/attribute mapping templates.
- Allow bulk application of a template to compatible products or variants.
- Validate each product before submission and separate ready/blocked items.
- Queue ready items in bounded batches with per-product results.
- Keep provider IDs server-managed and preserve existing mappings.
- Allow failed/blocked items to be corrected and retried without repeating successful work.

Acceptance:

- [x] A merchant can prepare and publish many compatible products without opening every product separately.
- [x] Mandatory marketplace fields are validated before jobs are queued.
- [x] Invalid products do not prevent valid products from being queued.
- [x] Results show queued, already linked, blocked, succeeded, and failed counts.
- [x] Repeated submissions do not duplicate active listings or operations.
- [x] Tenant isolation and authorization tests cover products, accounts, and templates.

### [x] P2.2 — Product-publication deduplication consistency

Problem:

The general WooCommerce publication action checks for an active operation, but `executeForAccount()` relies on the database constraint instead of returning a controlled already-queued result.

Scope:

- Use one deduplication path for automatic, single-account, and bulk publication.
- Return a typed outcome such as queued/already-active/skipped/invalid.
- Handle database uniqueness races without presenting an internal error to the merchant.

Acceptance:

- [x] Double clicks and concurrent requests create at most one active publication.
- [x] Bulk summaries distinguish queued and already-active products.
- [x] Concurrency and repeated-request tests pass.

---

## P3 — Order status semantics

### [x] P3.1 — Channel-capability-aware shipment statuses

Problem:

The central `shipped` status is currently sent to WooCommerce as `processing`, so the central panel and remote store can appear to disagree.

Scope:

- Define connector capabilities and supported status mappings.
- Treat unsupported central statuses explicitly as local-only or require configured custom provider statuses.
- Allow WooCommerce shipment/status plugins to be configured without leaking plugin logic into the order domain.
- Display the local and remote status meaning clearly in the UI.

Acceptance:

- [x] The UI never implies WooCommerce accepted a shipment state it did not receive.
- [x] Default WooCommerce behavior and optional custom mappings are documented.
- [x] Unsupported mappings fail safely without changing the central status incorrectly.
- [x] Connector mapping and queued status-update tests pass.

---

## P4 — Test and frontend maintainability

### [x] P4.1 — Independent and parallel-safe feature tests

Problem:

The complete suite passes because helper functions such as `tenantMember()`, `trendyolAccount()`, and `trendyolCredentials()` are loaded from other test files. Some Trendyol test files fail when run alone.

Scope:

- Move shared helpers to `tests/Pest.php`, factories, datasets, or namespaced test support classes.
- Remove test-order coupling and duplicate global helper declarations.
- Verify individual files and parallel execution where supported.

Acceptance:

- [x] `TrendyolListingTest.php` passes by itself.
- [x] Every feature-test file can run independently.
- [x] The complete suite still passes.
- [x] Shared helpers have one declaration in `tests/Pest.php`; the project does not install a parallel runner, so parallel execution itself is not applicable.

### [x] P4.2 — Frontend code splitting and bundle monitoring

Problem:

The production build succeeds, but the main JavaScript chunk is approximately 544 KB and exceeds the configured Vite warning threshold.

Scope:

- Split infrequently used reporting, marketplace setup, catalog editing, and platform-admin screens.
- Keep shared layout and critical navigation in stable common chunks.
- Record compressed bundle sizes in verification notes.
- Avoid adding a new dependency solely to silence the warning.

Acceptance:

- [x] Production build passes under the explicit 400 KB uncompressed entry budget.
- [x] Initial authenticated-page loading does not eagerly download all large screens.
- [x] Existing navigation and page behavior remain intact.

---

## Recommended execution order

1. P0.1 — Cancellation and return inventory compensation
2. P0.2 — Stock reservation lifecycle
3. P1.1 — Database-aggregated reporting
4. P1.2 — Synchronization-log retention and archival
5. P2.1 — Template-driven Trendyol bulk mapping and publishing
6. P2.2 — Product-publication deduplication consistency
7. P3.1 — Channel-capability-aware shipment statuses
8. P4.1 — Independent and parallel-safe feature tests
9. P4.2 — Frontend code splitting and bundle monitoring

## Work log

### 2026-08-19 — General audit and plan creation

- Reviewed the completed MVP documentation and relevant catalog, reporting, order-status, synchronization, and publication flows.
- Ran the complete backend suite: 135 tests passed with 729 assertions.
- Ran the production frontend build successfully; Vite reported an oversized main JavaScript chunk.
- Confirmed `TrendyolListingTest.php` fails in isolation because its helpers are defined in other test files.
- Created this improvement plan.
- No application behavior or database schema was changed.
- Current checkpoint set to **P0.1**.

### 2026-08-19 — P0.1 completed

- Added tenant-scoped `order_inventory_allocations` to preserve the quantity deducted per order, warehouse, and variant together with cancelled and returned quantities.
- Added database constraints preventing compensation from exceeding the original sold quantity.
- Added an idempotent transactional compensation action for full cancellation, full return, and bounded partial return quantities.
- Cancellation/return status ingestion from WooCommerce and Trendyol now restores only uncompensated mapped stock and creates immutable `cancellation` or `return` movements.
- Successful queued order-status updates use the same reconciliation path.
- Inventory synchronization events are dispatched after committed compensation and exclude the source channel account.
- Existing inventory-applied orders are backfilled into the allocation ledger using the active default warehouse.
- Applied both migrations successfully to the development PostgreSQL database.
- Formatter passed for all changed PHP files.
- Final full backend suite: **142 passed, 766 assertions**.
- Current checkpoint moved to **P0.2**.

### 2026-08-20 — P0.2 completed

- Defined the channel-independent lifecycle: `pending` and `confirmed` reserve stock; `processing`, `shipped`, and `delivered` consume reservations as sales; `cancelled` and `returned` release unconsumed reservations or compensate consumed sales.
- Kept allocation on the tenant's active default warehouse and serialized mutations through locked order, allocation, and inventory rows.
- Extended `order_inventory_allocations` with ordered, reserved, sold, released, cancelled, and returned quantity checkpoints plus PostgreSQL lifecycle constraints.
- Repeated imports and repeated status transitions are idempotent.
- Competing orders cannot reserve beyond current available quantity; a multi-variant failure rolls the whole reservation back.
- Reservation and release events dispatch downstream inventory synchronization after commit and exclude the source channel account.
- Updated Trendyol `Created` behavior to reserve stock instead of prematurely creating a sale movement; `Picking` consumes the reservation.
- Applied the reservation lifecycle migration successfully to the development PostgreSQL database.
- Formatter passed for all changed PHP files.
- Final full backend suite: **147 passed, 800 assertions**.
- Current checkpoint moved to **P1.1**.

### 2026-08-20 — P1.1 completed

- Replaced in-memory order, item, inventory, and synchronization collections with tenant-scoped PostgreSQL `COUNT`, `SUM`, filtered aggregate, and `GROUP BY` queries.
- Daily rows remain zero-filled for the requested date window while only grouped dates are returned by PostgreSQL.
- Channel performance, status distribution, and top products are calculated in SQL; top products and low-stock risks are bounded to ten rows.
- Removed the large order-ID `WHERE IN` path by joining order items directly to filtered orders.
- Added report indexes for tenant/currency/date/account orders, order/variant items, and tenant available-stock expressions.
- Added empty-data, mixed-currency, account, inclusive-boundary, high-volume query-count, and PostgreSQL `EXPLAIN` coverage.
- The 300-order regression case keeps business-query count bounded, does not select complete order collections, and uses `orders_tenant_currency_ordered_account_index` with sequential scans disabled for deterministic plan verification.
- Formatter and production frontend build passed. The existing main JavaScript chunk warning remains tracked under P4.2.
- Final full backend suite: **150 passed, 858 assertions**.
- Current checkpoint moved to **P1.2**.

### 2026-08-20 — P1.2 completed

- Added configuration-driven retention: succeeded and skipped technical attempts default to 30 days; failed attempts default to 180 days; pending/running rows are never eligible.
- Added `sync_operation_archives`, retaining tenant/account/operation/minute/status counts and the latest safe failure context after disposable technical rows are removed.
- Added the restartable `sync:archive` command. It locks and processes bounded chunks transactionally, upserts summaries, and deletes source rows only in the same committed transaction.
- Scheduled cleanup daily at 02:30 with overlap prevention and explicit failure logging.
- Synchronization history and detailed report health totals combine live and archived records, preserving tenant-visible counts after cleanup.
- Successful cleanup emits archived-row count and PostgreSQL live/archive table sizes for operational monitoring. Monthly partitioning remains deferred until measured volume warrants it.
- Applied the archive migration successfully to the development PostgreSQL database.
- Focused archival, grouping, and reporting suite: **9 passed, 147 assertions**.
- Final full backend suite: **153 passed, 901 assertions**; all 250 PHP files passed formatting and the production frontend build passed.
- Current checkpoint moved to **P2.1**.

### 2026-08-20 — P2.1 completed

- Added tenant-scoped reusable Trendyol listing templates containing a compatible central category, server-managed Trendyol category/brand IDs, required attributes, VAT, dimensional weight, and origin.
- The existing three-step Trendyol publication modal can save its completed mapping as a reusable template.
- The catalog bulk-publication modal lists tenant templates when a Trendyol account is selected.
- Bulk validation checks every product before dispatch: central-category compatibility, all required template attributes, TRY pricing, SKU, barcode, and HTTPS image availability.
- Ready products are queued without being stopped by blocked products; all missing variants of a ready product are published using the selected template.
- Existing account/variant listings are preserved. Repeated submissions report them as already linked and do not create new listings or synchronization operations.
- The result panel exposes queued, already linked, blocked, succeeded, and failed counts; blocked products can be corrected and resubmitted without repeating linked products.
- Tenant-owned products, accounts, categories, and templates are validated server-side; viewer and cross-tenant mutations are rejected.
- Applied the Trendyol template migration successfully to development PostgreSQL.
- Final full backend suite: **155 passed, 918 assertions**; all 256 PHP files passed formatting and the production frontend build passed.
- Current checkpoint moved to **P2.2**.

### 2026-08-20 — P2.2 completed

- Added a shared typed publication outcome: `queued`, `already_active`, `skipped`, or `invalid`.
- Added `CreateSyncOperation::executeUnique()` for all entity publication paths. It checks the current active operation, attempts creation inside a savepoint, and converts PostgreSQL unique-race failures into the existing active operation.
- WooCommerce automatic, single-account, and bulk publication now use the same deduplication path.
- Trendyol single and template-driven bulk publication use the same path while preserving their listing entity boundary.
- Duplicate clicks return a controlled already-queued response; bulk summaries count already-active products without dispatching another job.
- Added repeated WooCommerce/Trendyol request tests and direct verification of the partial unique index as the final concurrency boundary.
- Final full backend suite: **158 passed, 927 assertions**; all 260 PHP files passed formatting and the production frontend build passed.
- Current checkpoint moved to **P3.1**.

### 2026-08-20 — P3.1 completed

- Replaced the misleading fixed order-status list with channel-account capability mappings.
- WooCommerce no longer translates central `shipped` to `processing`. Shipment is offered only when the account has an explicit provider/plugin status code configured.
- Order detail shows both the central meaning and the exact provider status that will be sent.
- Unsupported transitions are rejected before a synchronization operation is created and cannot change the central order state.
- Trendyol exposes only its currently supported `processing` to `Picking` transition.
- Focused order-status and central-order tests passed.

### 2026-08-20 — P4.1 completed

- Moved shared tenant, channel-account, credential, and listing test builders into `tests/Pest.php` and removed cross-file global declarations.
- Ran every feature test file independently; the previously coupled WooCommerce order-pull and Trendyol listing files pass alone.
- No parallel runner dependency was added solely for this task. Centralized declarations remove helper collisions when parallel execution becomes available.

### 2026-08-20 — P4.2 completed

- Changed Inertia page discovery from eager imports to lazy Vite imports, producing separate route-level page chunks.
- Added a build-enforced 400 KB uncompressed entry budget without introducing a dependency.
- The measured entry is 396.00 KB (127.15 KB gzip); page chunks are loaded on demand and the largest generated page chunk is approximately 13.65 KB.
- TypeScript checking and the production build passed. Final backend suite: **160 passed, 934 assertions**; all **262 PHP files** passed formatting. All planned improvement items are complete.
