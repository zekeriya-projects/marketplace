# Current State

Updated: 2026-08-20

## Roadmap phase

Phase 14 — MVP Hardening is **complete and verified**. The documented MVP roadmap is complete.

The requested post-MVP catalog-management extension is complete: tenant-scoped categories, brands, reusable variant templates, a consolidated product-variant list, queued Excel/XML catalog imports, and downloadable Excel templates are available from the Turkish sidebar.

## Post-MVP improvement checkpoint

The general system audit and its step-by-step follow-up work are tracked in `docs/SYSTEM_IMPROVEMENT_PLAN.md`.

Current checkpoint: **complete**. Every P0–P4 item in the improvement plan is implemented and verified.

## Completed

- SaaS administrators have relational package and subscription management: editable monthly/yearly minor-unit pricing, trial periods, featured/active states, ordered feature entitlements and limits, organization assignment, billing cycle, period dates, cancellation state, and internal notes. Existing tenant plan/status fields remain synchronized for compatibility; payment collection is still intentionally out of scope.
- Organization settings uses separate Turkish profile, company, and user-management tabs. Profile details and the computed 14-day trial summary use real account dates; company contact, address, type, tax-office, and encrypted national-ID details are tenant-scoped and role-protected.
- Order-status actions are channel-capability aware. WooCommerce shipment is unavailable by default because core WooCommerce has no distinct shipped state; an account may provide its real shipment-plugin status code, which is stored in non-secret settings and displayed beside the central meaning before submission. Unsupported statuses are rejected without changing the order.
- Feature-test builders live centrally in `tests/Pest.php`, so feature files no longer depend on another file's load order and every feature file passes independently.
- Inertia pages are route-level lazy chunks. Production builds enforce a 400 KB uncompressed entry budget; the completion baseline is 396.00 KB (127.15 KB gzip), with page chunks loaded only when visited.

- Synchronization history is operation-oriented and scalable: records sharing tenant, channel account, operation type, and minute are summarized into one expandable task group instead of flooding the primary table with per-entity API attempts. The default window is seven days, server-side filters cover period/status/operation/account, group pagination uses a simple next/previous paginator, and only the newest 50 technical rows are loaded when a group is expanded. A tenant/date/account/operation composite index supports the grouping query at high volume.

- Tenant-scoped detailed reporting is available under `/reports` with date, currency, and channel-account filters. It includes sales/order/item/average-basket metrics, daily sales trends, channel performance, top products, order-status distribution, low-stock risks, discounts, shipping revenue, and synchronization health. Report filters reject cross-tenant account IDs and support a bounded one-year date range.

- Product-list marketplace mapping no longer asks merchants for external product IDs. The product-row `+` flow first selects an active channel account, then searches and lists remote WooCommerce or Trendyol products for direct selection. If no remote product exists, the merchant can continue through the existing three-stage basic information, mapping, and marketplace-specific publishing flow. Remote IDs remain server-managed, and mapping lookup/mutation is tenant-authorized.
- Product operations now support tenant-authorized bulk channel publishing from the catalog table. Merchants can select the current page or every product matching the active search (up to 500 per operation), choose multiple active channel accounts, and enqueue WooCommerce publication per product/account. Existing Trendyol links are preserved and products still missing mandatory marketplace category/attribute setup are reported for completion rather than being submitted with invalid data.

- Platform administration is separated from tenant roles through an explicit user-level flag and dedicated UI shell. SaaS administrators are redirected away from merchant product/inventory/order/channel operations and can only manage organization access plus manual subscription plan/status metadata; ordinary tenant users cannot access these routes. Payment collection and automated billing are not implemented.

- Variant terminology is separated: `/variant-definitions` manages reusable attributes such as Color and Size with their values, while `/variants` remains the sellable product/SKU list. Variant templates can only be assembled by selecting active, tenant-owned existing variant definitions; free-text template options are no longer accepted.
- Product create/edit now uses a Magenty-inspired detailed workspace with sticky General, Image, Description, and conditional Variants navigation. It persists grouping/invoice names, custom codes, compare-at and purchase prices in minor units, cargo desi values, VAT/ÖTV/ÖİV rates, exemption/expiry data, external-sync preference, and tenant-scoped product images. Inventory quantity deliberately remains variant/warehouse based instead of introducing `products.stock`.
- New-product creation now starts with a Turkish simple/variable product-type selection. Variable products require an active tenant-owned variant template; when none exists, the UI blocks progression and links directly to template creation. The same rule is enforced server-side, and selected template options are persisted on sellable variants.
- Catalog reference management adds hierarchical categories and brands with tenant-safe composite foreign keys, authorization, validation, usage protection, and product-form selectors.
- Variant management adds a server-paginated/searchable sellable-variant list and reusable option templates with tenant-scoped CRUD. Variant definition and template create/edit forms open in responsive right-side drawers instead of occupying the list header.
- Excel/CSV/XML files up to 20 MB are stored privately and processed asynchronously on the existing `imports` queue. Rows upsert canonical products by SKU, use integer minor-unit prices, create category/brand references, and write audited `import` inventory movements.
- Excel templates are generated at request time with a styled header, example row, currency/status validation lists, and a separate Turkish instructions sheet.
- PhpSpreadsheet 5.9 and the required PHP GD extension are included in the application image.

- Phase 14 authorization, tenant isolation, credential handling, pagination, rate-limit, failed-job, and database-index audits are complete and documented.
- Login/registration abuse limits, structured secret-free synchronization status logs, corrected Trendyol listing batch lifecycle, and pilot query indexes are implemented.
- Production deployment, pilot onboarding, backup/restore, monitoring/alerting, operator response, and security-audit runbooks are available under `docs/`.
- Phase 13 polls active Trendyol accounts every five minutes through the documented cursor stream endpoint, using fixed last-modified windows with overlap and advancing checkpoints only after complete success.
- Trendyol shipment packages are normalized into central orders with package identity, provider status, customer/address snapshots, exact minor-unit totals, and preserved line SKU/barcode identifiers.
- Repeated package imports update one central order and cannot apply inventory twice. Mapped lines create audited sale movements; unmapped lines remain visible without stock mutation.
- Inventory events originating from an imported channel order exclude that source account, preventing redundant echo updates while propagating the new stock to other active listings such as WooCommerce.
- Trendyol account UI supports an authorized manual pull and shows the latest imported, unmapped, and failed counts; scheduled and manual pulls share the same observable operation.
- Phase 12 routes committed canonical inventory and price events to active Trendyol listings through the existing provider-neutral dispatcher and dedicated queues.
- Trendyol stock updates send current sellable quantity across active warehouses; price updates send exact TRY decimal strings derived from integer minor units.
- Successful submissions retain the sync operation as running until Trendyol batch completion. Item rejection is stored as a sanitized failure; successful completion updates listing sync/price state.
- Trendyol sync jobs use a configurable Redis-backed provider rate limit plus bounded retry/backoff for network, 429, and temporary remote failures.
- Phase 11 Trendyol Product V2 publishing creates a persistent tenant-scoped listing mapping, submits through the queue, polls the returned batch request, and records active or safely rejected outcomes.
- Product publishing UI loads the Trendyol leaf-category tree, searches brands, renders category-specific attributes, and shows per-variant listing status without exposing credentials or raw provider failures.
- Eligible variants require an internal SKU, barcode, TRY price, HTTPS image, valid VAT/origin data, and an active tenant-owned Trendyol account.

- Application shell redesigned as a responsive operations dashboard with a persistent grouped sidebar, mobile drawer, workspace/profile header, active-route states, and consistent purple-accent visual system.
- Dashboard cards, seven-day sales chart, channel connections, and recent synchronization activity are populated from active-tenant data rather than demo values.

- Phase 10 Trendyol setup with encrypted seller credentials, PROD/STAGE selection, safe masking, tenant authorization, and a dedicated account UI.
- Trendyol HTTP client using fixed endpoints, Basic Auth, required seller-specific `User-Agent`, `storeFrontCode: TR`, bounded timeouts, and safe error translation.
- Queued connection tests reuse the provider-neutral sync lifecycle; success activates the account, while network, 429, and 5xx failures are retryable.
- Phase 9 WooCommerce order polling, normalized ingestion, successful-window checkpoints, exactly-once mapped inventory movements, and unmapped-item retention are complete.

- Phase 8 provider-independent orders and order items with UUIDv7 IDs, normalized statuses, integer minor-unit totals, external status/identity preservation, and historical customer/address snapshots.
- Idempotent ingestion serialized per channel account and enforced by unique `(channel_account_id, external_order_id)`; repeat imports update one order and atomically refresh its item snapshot.
- Mapping resolution prefers exact external product/variation identity, then SKU/barcode fallback, with explicit `mapped`, `unmapped`, and `ambiguous` outcomes. Unmapped items retain all external identifiers.
- Composite tenant foreign keys protect order/account and item/order/variant/listing agreement at the database boundary.
- Tenant-scoped order list/detail UI with server pagination, account/status filters, channel context, mapping visibility, exact currency formatting, and deliberately limited customer/address fields.
- All tenant roles may read their organization orders; no browser mutation/import endpoint exists in Phase 8.

- Phase 7 domain events for committed inventory and variant-price changes, with listeners that discover active mappings without coupling core domains to WooCommerce.
- One tenant-scoped sync operation/job per active listing for inventory and price pushes; pending/running operations are deduplicated in application code and by a PostgreSQL partial unique index.
- WooCommerce simple-product and variation updates using the correct provider endpoint, Basic Auth client, safe error translation, bounded retries, and dedicated `inventory-sync`/`price-sync` queues.
- Inventory push sends total available stock across active warehouses only. Price push sends exact decimal strings and rejects store/variant currency mismatches.
- Sync history exposes authorized manual retry for failed inventory/price operations; retries create a new auditable operation linked to the failed attempt.

- Phase 6 queued WooCommerce catalog import with independent remote-page jobs, bounded retry/backoff, and persisted page/product progress in the parent sync operation.
- Simple and variable product normalization into canonical products and sellable variants; variable attributes become readable variant names.
- Idempotent listing mappings keyed by WooCommerce account/product/variation identity. Re-import updates existing canonical records instead of duplicating them.
- Exact string-to-minor-unit price conversion, WooCommerce current-currency discovery, SKU conflict preservation through external listing identifiers, barcode/GTIN mapping, and sanitized plain-text descriptions.
- Unsupported product types and individual invalid products are isolated and counted; independently dispatched pages continue where possible.
- Account UI can start imports only after a successful connection and displays latest page/product progress. Duplicate concurrent imports are rejected.
- Horizon now explicitly consumes both `default` and `imports` queues with a worker timeout above the import job timeout.

- Phase 5 WooCommerce connection setup with encrypted credential replacement, safe consumer-key hinting, and no secret round-trip to the frontend.
- WooCommerce REST API v3 client using HTTPS Basic Auth, bounded connect/request timeouts, provider-safe error translation, and no query-string credentials.
- SSRF defenses reject non-HTTPS URLs and local/private/reserved targets; the runtime guard resolves every hostname before outbound requests.
- Queued connection tests create observable sync operations, activate accounts after success, and safely record permanent or exhausted retryable failures.
- WooCommerce account detail/setup UI with connection status, last-success timestamp, credential replacement, connection test dispatch, and sync-history link.

- Phase 4 marketplace-independent channel foundation with global WooCommerce and Trendyol reference channels.
- Tenant-scoped channel accounts with encrypted, serialization-hidden credentials; provider-neutral listing mappings; connector contracts; sync operation records; and a reusable queued sync lifecycle.
- Channels and synchronization-history screens with tenant-scoped data. Viewers remain read-only; owners, admins, and operators may create channel accounts.

- Phase 0 Docker environment, Phase 1 tenancy/authentication, and Phase 2 catalog remain operational.
- Tenant-scoped warehouses with tenant-unique codes, active/default flags, and at most one default warehouse per tenant.
- The first warehouse automatically becomes the tenant default; a later warehouse can explicitly replace it as default transactionally.
- One current `inventory_items` snapshot per tenant + warehouse + product variant, with on-hand, reserved, and derived available quantities.
- Immutable `inventory_movements` ledger with movement type, signed delta, before/after quantities, reason, actor, optional polymorphic reference, and timestamp.
- Manual stock adjustment uses signed deltas and requires a reason.
- Every adjustment creates/locks the current inventory row with PostgreSQL `FOR UPDATE`, validates non-negative available stock, records a balanced movement, and updates the snapshot in one transaction.
- Database constraints enforce non-negative quantities, reserved <= on-hand, non-zero movement deltas, balanced before/delta/after values, and cross-table tenant agreement.
- Inventory UI under `/inventory`: warehouse creation/selection, tenant-scoped variant search and server pagination, stock totals, manual adjustment, and paginated movement history.
- Viewer remains read-only; owner/admin/operator may create warehouses and adjust stock.
- Inventory navigation was added to the authenticated layout.

## Partially completed

- Inventory movement types are used by import, sale, cancellation, return, and manual adjustment flows; correction remains an internal type without a dedicated operator workflow.

## Remaining

No roadmap phase remains. The complete WooCommerce → canonical catalog → Trendyol → central order → inventory → WooCommerce MVP loop is operational and pilot hardening is documented.

## Important files created or changed

- Trendyol integration: `TrendyolClient.php`, `TrendyolConnector.php`, and `DTO/TrendyolCredentials.php`
- Trendyol setup: `SaveTrendyolCredentials.php`, `UpdateTrendyolCredentialsRequest.php`, account UI/controller/routes, and `config/services.php`
- Tests: `tests/Feature/TrendyolConnectionTest.php`

- Order domain: `OrderStatus.php`, `OrderItemMappingStatus.php`, normalized order DTOs, and `IngestOrder.php`
- Models/database: `Order.php`, `OrderItem.php`, `OrderFactory.php`, and `2026_08_10_000006_create_orders_tables.php`
- Authorization/UI: `OrderPolicy.php`, `OrderController.php`, `resources/js/Pages/Orders/Index.tsx`, `Show.tsx`, routes/navigation/CSS
- Tests: `tests/Feature/CentralOrdersTest.php`

- Events/listeners: `InventoryChanged.php`, `VariantPriceChanged.php`, `QueueVariantInventorySync.php`, `QueueVariantPriceSync.php`
- Sync dispatch/jobs: `DispatchVariantSync.php`, `SyncVariantInventoryJob.php`, `SyncVariantPriceJob.php`
- WooCommerce updates: inventory/price methods in `WooCommerceConnector.php` and `WooCommerceClient.php`
- Retry UI/API: `SyncOperationController.php`, `resources/js/Pages/Sync/Index.tsx`, and `routes/web.php`
- Database/test: `2026_08_10_000005_add_active_sync_deduplication.php`, `WooCommerceInventoryPriceSyncTest.php`

- Catalog import: `ImportWooCommerceProduct.php`, `WooCommerceCatalogImporter.php`, `WooCommerceProductPage.php`, and product/variation/currency methods in `WooCommerceClient.php`
- Queue/progress: `ImportWooCommerceProductsPageJob.php`, import endpoint in `ChannelAccountController.php`, and `config/horizon.php`
- Frontend: import controls and progress in `resources/js/Pages/Channels/WooCommerce/Show.tsx`
- Tests: `tests/Feature/WooCommerceCatalogImportTest.php`

- WooCommerce integration: `app/Integrations/WooCommerce/WooCommerceClient.php`, `WooCommerceConnector.php`, `WooCommerceUrlGuard.php`, and `DTO/WooCommerceCredentials.php`
- Connection workflow: `SaveWooCommerceCredentials.php`, `UpdateWooCommerceCredentialsRequest.php`, `TestChannelConnectionJob.php`, `RetryableSyncException.php`
- UI/routes: WooCommerce account methods in `ChannelAccountController.php`, `resources/js/Pages/Channels/WooCommerce/Show.tsx`, channel list links, and `routes/web.php`
- Tests: `tests/Feature/WooCommerceConnectionTest.php`

- Inventory domain: `app/Domain/Inventory/Enums/InventoryMovementType.php`, `Actions/AdjustInventory.php`, `Actions/CreateWarehouse.php`
- Models: `app/Models/Warehouse.php`, `InventoryItem.php`, `InventoryMovement.php`; relationships added to `Tenant.php` and `ProductVariant.php`
- Policy/validation: `app/Policies/WarehousePolicy.php`, `StoreWarehouseRequest.php`, `AdjustInventoryRequest.php`
- HTTP/routes: `app/Http/Controllers/InventoryController.php`, `WarehouseController.php`, `routes/web.php`, policy registration in `AppServiceProvider.php`
- Database: `database/migrations/2026_08_08_000003_create_inventory_tables.php`, `WarehouseFactory.php`
- Frontend: `resources/js/Pages/Inventory/Index.tsx`, `Show.tsx`, authenticated navigation, inventory CSS
- Tests: `tests/Feature/InventoryTest.php`
- Docs: `docs/CURRENT_STATE.md`, `docs/DECISIONS.md`

## Migrations created

- `2026_08_11_000009_create_catalog_reference_tables.php` adds tenant-scoped hierarchical categories and brands plus composite-safe product references.
- `2026_08_11_000010_create_variant_templates.php` adds reusable tenant variant templates and structured option values on variants.
- `2026_08_11_000011_create_catalog_imports.php` adds tenant-scoped, observable catalog import records and progress counters.

- `2026_08_10_000006_create_orders_tables.php` — creates tenant-safe orders/items, external-order idempotency, money/quantity constraints, snapshot JSONB fields, and supporting listing composite key.

- `2026_08_11_000008_add_pilot_query_indexes.php` — adds composite indexes for listing barcode mapping, tenant/account/status order pagination, and synchronization-status monitoring.

- `2026_08_10_000005_add_active_sync_deduplication.php` — prevents more than one pending/running operation for the same account, operation, and mapped entity.

- `2026_08_10_000004_create_channel_foundation_tables.php` — creates global channels plus tenant-scoped channel accounts, listings, and synchronization operations with composite tenant foreign keys and mapping uniqueness.

- `2026_08_08_000003_create_inventory_tables.php` — adds the supporting `(tenant_id,id)` product-variant unique key and creates `warehouses`, `inventory_items`, and `inventory_movements` with tenant-integrity, uniqueness, ledger-balance, and non-negative-stock constraints.

All migrations are applied in the development PostgreSQL database. Clean migration was verified successfully. Future clean migration checks must explicitly set `DB_DATABASE=marketplace_saas_testing`; `--env=testing` alone does not load PHPUnit database overrides.

## Architectural decisions

- Keep provider parsing outside the order domain. `IngestOrder` accepts immutable normalized DTOs containing integer money, normalized status, snapshots, and preserved external item identity.
- Serialize ingestion on the channel-account row and enforce `(channel_account_id, external_order_id)` uniqueness. Re-import updates the existing order and replaces its item snapshot in one transaction.
- Resolve mappings first by exact external product/variation identity, then unique external SKU or barcode. Never guess when a fallback has multiple matches; retain the item as ambiguous.
- WooCommerce order polling now maps provider payloads into the central order DTO boundary. Mapped lines reduce stock in the active default warehouse once; `inventory_applied_at` prevents repeated imports from producing duplicate sale movements.
- Order polling uses fixed UTC modified-time windows with a five-minute overlap. The persisted account/resource checkpoint advances only after every queued page succeeds.
- Unmapped or ambiguous lines remain visible with their external identity and never guess at an inventory mutation.
- Store complete normalized snapshots but expose only known customer/address keys in Inertia responses, reducing accidental personal-data disclosure from future connector-specific fields.

- Core inventory/catalog actions emit provider-neutral events after successful writes. Listeners resolve active channel listings and create provider jobs outside the domain transaction.
- Compute channel stock as the sum of `quantity - reserved_quantity` across active warehouses at job execution time, so retries send the newest canonical value rather than stale event payloads.
- Treat price payloads as exact decimal strings derived from integer minor units. Reject currency mismatch rather than silently sending an amount in the wrong store currency.
- Coalesce rapid changes while one operation is pending/running, enforced with a PostgreSQL partial unique index. The queued job reloads current state, so one later execution covers intermediate changes.
- Manual retry always creates a new sync operation with `retry_of` context; historical failures remain immutable audit records.

- Fetch WooCommerce products in stable ID-ascending pages of 50 and variations in provider-supported pages of 100. After page one reveals `X-WP-TotalPages`, dispatch every remaining product page independently.
- Keep raw provider mapping inside the WooCommerce integration/application boundary. Core catalog models store normalized fields; external IDs remain only in channel listings.
- Make the listing mapping the idempotency key. Existing mapped products/variants are updated on repeated import; absent mappings create internal UUID entities transactionally.
- Do not merge an imported variant into an unrelated canonical variant automatically. When its SKU is already occupied, preserve the external SKU on the listing and leave the new internal SKU null.
- Store import progress in safe sync-operation context (`total_pages`, processed/failed pages, imported/failed/skipped counts), never raw product payloads.

- Authenticate WooCommerce REST API v3 over HTTPS with HTTP Basic Auth. Credentials are never placed in query parameters because URLs are commonly logged by intermediaries.
- Test authentication against `/wp-json/wc/v3/data`; the API index is deliberately not used because WooCommerce documents it as unauthenticated.
- Treat connection tests as queued synchronization operations. Network, 429, and remote 5xx failures use bounded retry/backoff; validation and authentication failures are terminal.
- Reject unsafe store URLs before the request and re-resolve them in the HTTP client to reduce server-side request forgery risk.

- Channel accounts and listings are provider-neutral. WooCommerce/Trendyol HTTP behavior will live behind connector implementations in later phases.
- Credentials use Laravel's encrypted array cast and are hidden from model serialization. Phase 4 accepts no provider secrets from the browser.
- Sync jobs share attempt, timing, backoff, timeout, success, and safe-failure bookkeeping; provider-specific work remains deferred.

- Inventory remains a module inside the Laravel monolith and has no dependency on channels or marketplace classes.
- Current stock is a warehouse/variant snapshot; the movement table is the immutable audit ledger.
- Manual adjustment accepts a signed delta rather than an absolute replacement quantity, preserving the exact user intent in the ledger.
- A missing inventory row is inserted idempotently, then re-read under `FOR UPDATE`; concurrent mutations serialize on that row.
- Snapshot update and movement creation share one transaction with limited deadlock retry. Invalid/negative outcomes roll back both.
- Tenant agreement among inventory, warehouse, and variant is enforced with composite foreign keys, in addition to active-tenant policies and service validation.
- Database check constraints independently protect stock and movement invariants.
- Historical movements reject Eloquent update/delete operations. No movement deletion endpoint exists.
- The `order_id` field suggested in `DATABASE.md` is deliberately deferred until the Phase 8 order schema exists; the optional UUID polymorphic reference columns preserve a future linkage path without creating a premature order dependency.

## Known bugs or problems

- No functional MVP blocker is known after Phase 14 verification.
- During verification, `php artisan migrate:fresh --env=testing` targeted the development database because Laravel CLI did not consume PHPUnit's testing variables. The database was rebuilt and contained zero users, tenants, products, and warehouses when checked. No existing row loss was observable, but the command must not be repeated without explicitly setting `DB_DATABASE=marketplace_saas_testing`.
- There is currently no admin/user account in the development database. Register through <http://localhost:8080/register> to create an owner and tenant.
- Existing non-blocking Vite `fontaine` optional fallback notice remains.
- The checkout is on `/mnt/d` rather than the recommended WSL2 Linux filesystem path; moving it to `~/projects/marketplace-saas` remains recommended for bind-mount performance.
- The supplied checkout has no `.git` metadata, so Git diff/status and commit creation are unavailable.

## Test status

- Pest: **104 passed, 549 assertions**.
- Catalog import/reference-specific: **7 passed, 54 assertions**, covering tenant-scoped CRUD, viewer restrictions, queued upload, XML idempotency, inventory movement creation, and generated workbook validity.
- MVP-hardening-specific: **3 passed** covering login throttling, exhausted Trendyol batch failure safety, and structured secret-free synchronization logging.
- Trendyol-order-specific: **5 passed** covering exact normalization, idempotent stock mutation, source-account exclusion/downstream WooCommerce sync, cursor/checkpoint continuation, unmapped retention, and manual-pull authorization/deduplication.
- Trendyol-inventory/price-specific: **4 passed** covering sellable-stock payloads, exact prices, asynchronous batch completion/rejection, provider throttling, and retryable 429 responses.
- Trendyol-listing-specific: **5 passed** covering tenant-scoped queueing, Product V2 submission, asynchronous batch success/rejection, safe failure persistence, and authorized category/brand/attribute lookups.
- Dashboard-specific: **1 passed, 22 assertions** covering real metrics, recent data, and cross-tenant exclusion.
- Trendyol-connection-specific: **7 passed, 43 assertions** covering encryption/masking, validation, tenant authorization, secret-free jobs, PROD/STAGE routing, required headers, activation, and safe retry/error translation.
- WooCommerce-order-pull-specific: **5 passed, 28 assertions** covering exact money mapping, exactly-once inventory effects, unmapped retention, page fan-out/checkpointing, authorization, and duplicate-run prevention.
- Central-orders-specific: **5 passed, 32 assertions** covering normalized mapped ingestion, repeated-import idempotency, unmapped/ambiguous identity preservation, tenant pagination/filtering, authorization, and database tenant integrity.
- WooCommerce-inventory/price-specific: **5 passed, 16 assertions** covering committed inventory dispatch, price deduplication, active-warehouse availability, variation payloads, exact prices, currency rejection, and authorized manual retry.
- WooCommerce-catalog-import-specific: **5 passed, 27 assertions** covering repeated-import idempotency, variable normalization/mapping, remote currency, page fan-out/progress, and authorization/account-state gates.
- WooCommerce-connection-specific: **8 passed, 47 assertions** covering credential encryption/masking, validation, tenant authorization, safe job payloads, Basic Auth, activation, error sanitization, retry exhaustion, and private-network blocking.
- Channel-foundation-specific: **6 passed, 29 assertions** covering reference channels, tenant isolation and viewer authorization, encrypted/hidden credentials, cross-tenant listing rejection, sync lifecycle, and connector resolution.
- Inventory-specific: **8 passed, 43 assertions** covering default warehouse creation, balanced ledger writes, serialized snapshot mutations, negative-stock rollback, viewer authorization, cross-tenant mutation denial, tenant-scoped inventory listing, and movement immutability.
- Pint: **PASS**, 198 files.
- TypeScript: **PASS**, `tsc --noEmit`.
- Vite production build: **PASS**, 776 modules transformed.
- Development migration and explicit clean `marketplace_saas_testing` migration: **PASS**; all eleven migrations applied.
- Nginx login: HTTP 200; unauthenticated inventory route: expected HTTP 302.
- PostgreSQL: accepting connections; Redis: `PONG`.
- Horizon: running; real smoke token `phase3-20260808` processed successfully.

## Docker/service status

At final verification, `nginx`, `app`, `postgres`, `redis`, `horizon`, and `scheduler` were all healthy. The stack is left running at <http://localhost:8080>.

## Exact recommended next task

Run a pilot onboarding rehearsal using `docs/PRODUCTION.md`, then prioritize post-MVP work only from merchant feedback. Do not add another marketplace opportunistically.

## Commands the next session should run first

```bash
cat AGENTS.md
find docs -maxdepth 1 -type f -print -exec cat {} \;
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pint --test
docker compose exec app npm run typecheck
docker compose ps
```

If a clean test-database migration is needed, use an explicit database override:

```bash
docker compose exec -e DB_DATABASE=marketplace_saas_testing app php artisan migrate:fresh --force
```
# Ticimax integration

Ticimax is registered as a storefront channel. Its encrypted per-account credentials contain the HTTPS store URL and Web Service `UyeKodu`. Connection tests use `SelectUrunCount`; mapped active listings receive queued stock updates through `StokAdediGuncelle` and price updates through `VaryasyonGuncelle`. Listings must preserve Ticimax product and variation IDs, and price mappings must include `metadata.ticimax_currency_id`.
