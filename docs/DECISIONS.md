# Technical Decisions

## 2026-08-19 — WooCommerce canonical catalog publishing

- Treat centrally created catalog products as publishable to every active WooCommerce account unless external synchronization is disabled on the product.
- Queue one idempotent product publication operation per account and persist one channel listing mapping per sellable variant.
- Publish single-variant products as WooCommerce simple products and multi-variant products as variable products with variation mappings.
- Update existing remote IDs on later saves instead of creating duplicate WooCommerce products. Inventory and price events continue to use the persisted variant listings.
- Keep WooCommerce payload mapping, HTTP/OAuth behavior, and external identifiers inside the WooCommerce integration module.

## 2026-08-08 — Phase 0 runtime layout

- Use a single Laravel modular-monolith image for the `app` PHP-FPM, Horizon, and scheduler processes. Separate Compose services allow independent lifecycle/health management without creating microservices.
- Put Laravel behind Nginx; do not expose PHP-FPM directly.
- Use PostgreSQL 18 and Redis containers with named persistent volumes and environment-driven Laravel configuration.
- Run Horizon against the Redis queue connection and run the scheduler as an independent `php artisan schedule:work` process.
- Use named volumes for `vendor`, `node_modules`, and `public/build` alongside the development source bind mount so image-installed dependencies and built assets remain visible.
- Verify real queue processing with an infrastructure-only job that writes a ten-minute Redis cache marker. This avoids prematurely adding sync/business schema.
- Keep local development Docker-first and document the WSL2 Linux filesystem as the supported checkout location.

## 2026-08-08 — PHP runtime version

Use PHP 8.4 for the application image. Laravel 13's resolved Symfony 8.1 dependencies require PHP >= 8.4.1, and PHP 8.4 satisfies the project's PHP 8.3+ requirement without artificially constraining dependency resolution to older transitive packages.

## 2026-08-08 — Database and cache network exposure

- Mount the PostgreSQL 18 data volume at `/var/lib/postgresql`, as required by the official version-18 image's major-version-specific data directory layout.
- Do not publish PostgreSQL or Redis ports to the host by default. Both services are application-internal in Phase 0 and remain reachable to Laravel over the private Compose network; this avoids collisions with existing local services and reduces unnecessary exposure.

## 2026-08-08 — Dynamic PHP-FPM service discovery

Resolve the `app` upstream through Docker's embedded DNS from Nginx with a short validity window. A development container can be recreated with a new IP while Nginx remains running; dynamic resolution prevents Nginx from retaining the former PHP-FPM address and returning 502 responses.

## 2026-08-08 — Phase 1 tenant context and authorization

- Use a shared-schema tenancy model with UUIDv7 tenant IDs, a `tenant_user` membership pivot, and finite `owner`, `admin`, `operator`, and `viewer` roles.
- Keep the existing Laravel bigint user ID for migration compatibility with Phase 0. Tenant and future public business identifiers use UUIDv7 where practical.
- Store the selected tenant as `users.active_tenant_id`, but resolve and validate it against authenticated membership on every tenant-protected request. Browser-provided tenant IDs never establish context.
- Bind `TenantContext` as a request-scoped service. Domain/application code receives explicit context rather than relying on an untestable static global.
- Use policies for tenant read/update/switch/member-management authorization. Owners and admins manage the organization; only owners may manage privileged roles; transactions and row locking guarantee that at least one owner remains.
- Registration creates the user, first tenant, owner membership, and active tenant in a transaction.

## 2026-08-08 — Isolated PostgreSQL test database

Use `marketplace_saas_testing` as a separate PostgreSQL database created by the Postgres initialization script. The Docker environment exports `APP_ENV=local`, so the base test case sets testing variables before Laravel boots; `php artisan test` cannot refresh the development database accidentally.

## 2026-08-08 — Phase 2 central catalog model

- Model a product as the tenant-owned commercial parent and a product variant as the sellable SKU. Inventory remains a later domain and is not represented by a stock column on either catalog table.
- Use UUIDv7 primary identifiers for both catalog entities and copy `tenant_id` onto variants. Enforce variant/product tenant agreement with a composite PostgreSQL foreign key, not only application code.
- Keep SKU nullable and unique per tenant. Index barcode per tenant without making it unique because suppliers and channel data may contain shared or imperfect barcodes.
- Store base prices as integer minor units with a three-letter currency code. Parse decimal form values using string arithmetic so monetary input never passes through floating point.
- Put multi-record catalog writes in a transactional `SaveProduct` action. Do not implicitly delete omitted existing variants during Phase 2 edits; destructive variant lifecycle behavior requires an explicit future rule.
- Use active-tenant product policies: viewers are read-only, while owners, admins, and operators can create and update. Use PostgreSQL `ILIKE` for the required server-side catalog search because PostgreSQL is the sole supported database.

## 2026-08-08 — Phase 3 warehouse inventory ledger

- Represent current stock with one `inventory_items` snapshot per tenant, warehouse, and variant. Preserve `reserved_quantity` and compute available as on-hand minus reserved, while deferring reservation workflows.
- Treat `inventory_movements` as an immutable ledger. Store signed delta, before/after values, reason, actor, and an optional UUID polymorphic reference. Application model events reject historical update/delete attempts.
- Express manual adjustment as a signed delta, not an absolute overwrite. Insert a missing zero snapshot idempotently, lock it with PostgreSQL `FOR UPDATE`, validate the resulting available quantity, create the movement, and update the snapshot in one transaction.
- Add database check constraints for non-negative on-hand/reserved quantities, reserved not exceeding on-hand, non-zero movement delta, and `after = before + delta`.
- Enforce tenant equality between inventory rows, warehouses, and variants with composite foreign keys. Add the required `(tenant_id,id)` supporting unique keys and keep application-level active-tenant authorization as a second boundary.
- Permit one default warehouse per tenant using a PostgreSQL partial unique index. The first warehouse becomes default automatically; selecting a new default clears the old flag transactionally.
- Keep owner/admin/operator inventory writes and viewer read-only access consistent with the catalog role boundary.
- Defer `order_id` until the central order phase defines that table. Do not introduce channel synchronization events or jobs during Phase 3.

## 2026-08-08 — Safe clean-migration command

Laravel CLI `--env=testing` does not consume the PHPUnit environment variables established by `tests/TestCase.php`. Any direct destructive migration verification must explicitly set `DB_DATABASE=marketplace_saas_testing`; otherwise it can target the development database. The recommended command is `docker compose exec -e DB_DATABASE=marketplace_saas_testing app php artisan migrate:fresh --force`.

## 2026-08-10 — Phase 4 channel integration foundation

- Keep `channels` as global reference data and insert WooCommerce/Trendyol during migration. Channel accounts, listings, and sync operations are tenant-owned and use composite foreign keys for tenant agreement.
- Store credentials through Laravel's encrypted array cast and hide them from serialization. Provider-specific credential validation and UI are deferred to each connection phase.
- External identities remain listing mappings; internal UUIDs stay canonical. A PostgreSQL partial expression index prevents duplicate external entities within an account.
- Provider-neutral contracts and `ConnectorManager` preserve `Core -> Contracts <- Adapter`. Shared queued-job lifecycle records bounded retries, timing, attempts, and safe failures.

## 2026-08-10 — Phase 5 WooCommerce connection

- Use Laravel's HTTP client directly rather than introducing a WooCommerce SDK. Phase 5 needs one authenticated probe, and the existing integration boundary isolates future endpoint growth without a new major dependency.
- Require HTTPS and send consumer credentials only through HTTP Basic Auth. Do not implement the documented query-string fallback because URLs may be retained in proxy/access logs.
- Probe `/wp-json/wc/v3/data`, which requires authenticated read access. The API index is unsuitable as a credential test because it is public.
- Validate URL shape at input and enforce public DNS/IP resolution immediately before outbound calls. Reject localhost and private/reserved targets to protect the application network from SSRF.
- Queue connection tests and persist them as `connection_test` sync operations. Successful tests activate the account; terminal failures mark it errored; transient network, rate-limit, and server errors use the shared bounded retry lifecycle.
- Never return stored keys to Inertia. The setup page receives only store URL, configured state, and the final four consumer-key characters; replacing credentials requires both values again.

## 2026-08-10 — Phase 6 WooCommerce catalog import

- Import product collections in ID-ascending pages of 50. Page one reads `X-WP-TotalPages` and fans out remaining pages as independent `imports` queue jobs, allowing isolated retries and avoiding controller/worker-wide loops.
- Retrieve variable-product variations through their dedicated endpoint in pages of 100. A transient variation failure retries its product page safely because all writes are idempotent.
- Use `(channel_account_id, external_product_id, external_variant_id)` listing identity as the import idempotency boundary. Repeated imports update mapped internal records and never use WooCommerce IDs as primary keys.
- Preserve conservative catalog ownership: do not auto-merge unrelated internal variants on SKU alone. A conflicting SKU remains available as `external_sku` on the mapping while the imported internal SKU is null.
- Fetch the store's current ISO currency from WooCommerce before the first product page and persist it in account settings. Continue using string-only minor-unit conversion for imported prices.
- Sanitize provider HTML descriptions to plain text and store only minimal mapping metadata. Raw catalog payloads and credentials never enter sync-operation context or application logs.
- Explicitly configure Horizon to consume `default` and `imports`; set its worker timeout above the import job timeout so Horizon does not terminate valid jobs early.

## 2026-08-10 — Phase 7 WooCommerce inventory and price sync

- Emit provider-neutral inventory/price events only after successful central writes. Listeners outside core domains resolve active listings and dispatch provider operations after the database transaction.
- Jobs serialize identifiers only and reload listing, variant, inventory, account, and credentials at execution time. Retries therefore push current canonical values rather than stale snapshots.
- Aggregate available inventory across active warehouses. Disabled warehouses do not contribute to channel stock, and reserved quantity is excluded.
- Send WooCommerce stock with `manage_stock=true` and integer `stock_quantity`; send prices as exact `regular_price` decimal strings. Use product or variation endpoints according to the stored mapping.
- Reject price synchronization when variant and WooCommerce store currencies differ. No implicit conversion or floating-point currency calculation is permitted.
- Deduplicate pending/running operations per account/operation/entity with both an application check and a partial unique database index.
- Manual retry creates a new operation linked by safe `retry_of` context. Failed records are retained and viewers cannot initiate retries.

## 2026-08-10 — Phase 8 central orders

- Define immutable normalized order DTOs as the provider boundary. Core ingestion receives normalized statuses, integer minor units, snapshots, timestamps, and external identifiers rather than WooCommerce/Trendyol payloads.
- Lock the channel-account row while ingesting and enforce unique `(channel_account_id, external_order_id)`. This serializes competing imports for one provider account and guarantees one central order.
- Repeated ingestion updates the order snapshot and atomically replaces its items. Phase 8 deliberately has no stock side effects, so replacement is safe; Phase 9 must add exactly-once inventory behavior around provider ingestion.
- Match order items by exact external product/variation mapping first, then by unique external SKU or barcode. Multiple fallback matches are `ambiguous`; missing matches are `unmapped`; both preserve external identity without inventory guesses.
- Enforce tenant agreement with composite foreign keys from orders to accounts and items to orders, variants, and listings. Restrict deletion of mapped catalog/listing rows referenced by historical items.
- Persist complete normalized customer/address snapshots for historical accuracy, while order UI serialization allowlists known display fields to limit personal-data exposure.
- Keep the existing polymorphic inventory-movement reference instead of adding a redundant `order_id`; Phase 9 can reference an order through that audited UUID relationship.

## 2026-08-11 — Phase 9 WooCommerce order import

- Poll WooCommerce orders every five minutes through queued, independently retryable pages. Manual pulls use the same dispatch path and active operations are deduplicated per account.
- Query by a fixed UTC `modified_after`/`modified_before` window and retain a five-minute overlap. Advance `channel_sync_states` only when all pages succeed, so failure cannot create a silent gap.
- Convert every documented decimal string directly to integer minor units. Provider statuses and customer/address data are normalized inside the WooCommerce adapter.
- Apply mapped quantities to the tenant's active default warehouse under row locks. Store an immutable `sale` movement referencing the central order and mark `inventory_applied_at` in the same transaction.
- Treat `inventory_applied_at` as the Phase 9 exactly-once boundary. Re-imports may refresh the order snapshot but cannot deduct the same external order twice; cancellation/refund compensation remains a future explicit workflow.
- Preserve unmapped and ambiguous lines without stock effects. A mapped order with no active default warehouse or insufficient available stock remains imported, while its page is reported as failed and the checkpoint does not advance.

## 2026-08-20 — Order stock reservation lifecycle

- Reserve mapped quantities in the tenant's active default warehouse when a normalized order is `pending` or `confirmed`. Reservation changes `reserved_quantity`, not on-hand `quantity`, so available stock immediately falls without creating a sale movement.
- Convert the reservation to one immutable `sale` movement when the order reaches `processing`, `shipped`, or `delivered`. Decrease on-hand and reserved quantities together so available stock does not change a second time.
- On `cancelled` or `returned`, release any unconsumed reservation. If sale consumption already occurred, use the idempotent cancellation/return compensation ledger instead.
- Store ordered, currently reserved, sold, released, cancelled, and returned checkpoints per tenant, order, warehouse, and variant in `order_inventory_allocations`. PostgreSQL constraints prevent lifecycle totals and compensation totals from exceeding the original order allocation.
- Lock the order, allocation, and inventory snapshots transactionally. Competing orders therefore cannot reserve the same available units, and a failure on any variant rolls back the whole order mutation.
- Dispatch inventory synchronization only after commit and exclude the source channel account. Connectors continue calculating channel stock from active-warehouse `quantity - reserved_quantity`.

## 2026-08-20 — Database-aggregated detailed reporting

- Keep the existing synchronous report screen and bounded one-year filter, but calculate summaries in PostgreSQL rather than hydrating all matching Eloquent models.
- Join order items directly to tenant/date/currency/account-filtered orders. Never construct a large order-ID list for report calculations.
- Return only grouped daily/channel/status rows, the top ten products, and the ten lowest available inventory rows. Fill missing chart dates in application memory because that collection is bounded to 367 entries.
- Use PostgreSQL filtered aggregates for synchronization health and integer sums for every monetary result.
- Support the report path with tenant/currency/date/account, order/variant, and available-stock expression indexes. Preserve an automated `EXPLAIN` assertion using disabled sequential scans so index eligibility remains deterministic on small test data.
- Do not cache operational report data yet. The bounded aggregate queries are inexpensive and immediate correctness is preferable until production measurements justify tenant/filter-aware caching.

## 2026-08-11 — Phase 10 Trendyol connection

- Store seller ID, API key, API secret, and explicit production/stage environment in the existing encrypted channel-account credential field. Only safe hints return to the UI.
- Keep Trendyol base URLs in application configuration rather than accepting arbitrary provider URLs from the browser. Production and stage credentials are distinct.
- Authenticate with API key/secret Basic Auth and send the required seller-specific `User-Agent` plus `storeFrontCode: TR`.
- Test credentials through the documented seller-address endpoint because it validates seller identity and permission without mutating remote state.
- Reuse the queued connection-test lifecycle. Authentication failures are terminal; network, rate-limit, and remote-server failures use bounded retries and sanitized messages.
- Defer category, attribute, listing, inventory, price, and order behavior to the following Trendyol roadmap phases.

## 2026-08-11 — Application navigation and dashboard refresh

- Use a persistent grouped left sidebar on desktop and an off-canvas drawer on mobile. Show only routes backed by implemented product behavior; future marketplace modules are not represented as working links.
- Keep the application shell provider-neutral. Marketplace-specific setup remains behind the shared Channels navigation item.
- Populate dashboard metrics, weekly sales, connections, and recent operations exclusively from the authenticated active tenant. No placeholder business figures are presented as real data.
- Preserve the existing form, table, and panel class vocabulary while applying one coherent responsive visual system across all implemented screens.

## 2026-08-11 — Phase 11 Trendyol listings

- Publish only eligible canonical variants and retain internal UUIDs as product/variant identity. Trendyol identifiers and the asynchronous batch request ID remain mapping metadata, never internal primary keys.
- Use Trendyol Product V2 through an `imports` queue job. Persist the mapping before dispatch, then poll the provider batch endpoint with bounded retries because a successful submission is not proof of publication.
- Store provider rejection as a generic actionable message and stable internal error code. Raw item failure payloads are deliberately excluded from listing metadata and synchronization logs.
- Limit catalog lookup support to the publishing flow: leaf categories, brand name search, and category attributes. Do not introduce a universal category/PIM domain for the MVP.
- Resolve every lookup through an authorized, active, tenant-owned Trendyol account. The frontend receives only public catalog IDs/names and attribute definitions; credentials remain encrypted server-side.
- Build prices from integer minor units and submit exact decimal strings. Inventory is calculated from active warehouses at job execution time; ongoing Trendyol inventory and price propagation remains Phase 12.

## 2026-08-11 — Phase 12 Trendyol inventory and price sync

- Reuse the provider-neutral inventory/price events, listing discovery, active-operation deduplication, and manual retry flow. Core catalog and inventory code remains unaware of Trendyol.
- Call Trendyol's shared `price-and-inventory` endpoint with one changed listing per operation. Inventory sends sellable stock only; price sends equal list/sale values as exact decimal strings from minor units.
- Keep the synchronization operation in `running` state after submission and complete it only after the returned batch request reports item-level success. Provider rejection payloads are replaced with a stable internal code and safe message.
- Cap outbound sellable quantity at Trendyol's documented 20,000-unit ceiling and never send a negative value.
- Apply a configurable Redis-backed Trendyol request limiter before jobs call the provider. Keep HTTP 429, network, and temporary server failures in the shared bounded retry lifecycle.

## 2026-08-11 — Phase 13 Trendyol order import

- Poll Trendyol's shipment-package stream endpoint every five minutes with opaque cursor pagination. Use a fixed last-modified window per operation, a five-minute overlap, and a fourteen-day initial lookback within the provider's documented range.
- Treat the Trendyol shipment package ID as `external_order_id` and preserve the customer-facing order number separately. This keeps split packages idempotent under the central `(channel_account_id, external_order_id)` constraint.
- Normalize statuses, addresses, line identifiers, SKU/barcode, and monetary values inside the Trendyol adapter. The order domain continues to accept only provider-neutral DTOs and integer minor units.
- Reuse the existing `inventory_applied_at` exactly-once boundary. Repeated pulls may refresh order/item snapshots but never create a second sale movement for the same package.
- Add the source account ID to order-originated inventory events and exclude it during listing discovery. A Trendyol sale therefore updates WooCommerce and other channels without echoing unchanged stock back to its originating Trendyol account.
- Keep unmapped or ambiguous items visible and count them in synchronization context. They do not mutate stock; no SKU or barcode match is guessed when multiple mappings exist.
- Advance `channel_sync_states` only after the final cursor page succeeds without item failures. Network, rate-limit, and temporary provider failures remain retryable and cannot silently move the checkpoint.

## 2026-08-11 — Phase 14 MVP hardening

- Apply named identity/IP rate limits to login and registration. Keep channel API limits separate and provider-configurable through Redis-backed queue middleware.
- Observe synchronization status transitions centrally and emit structured logs containing internal identifiers, status, attempts, and normalized error codes only. Do not log credentials, raw provider bodies, authorization headers, or customer snapshots.
- Keep asynchronous listing publication `running` until Trendyol confirms the batch. Exhausted batch-check retries must mark the persisted operation failed instead of leaving an apparently successful submission.
- Add composite indexes matching pilot query paths: listing barcode mapping, tenant/account/status order pagination, and tenant sync-status history. Preserve existing database constraints as the correctness boundary.
- Treat the provided Docker Compose file as a local-development topology. Production uses an immutable image, private managed PostgreSQL/Redis/object storage, TLS termination, external secrets, separate worker/scheduler processes, verified backups, and documented monitoring.
- Define pilot backup targets of 24-hour RPO without WAL archiving (15 minutes with PITR) and four-hour RTO, verified through monthly isolated restore drills.

## 2026-08-20 — Synchronization-log retention

- Keep raw succeeded and skipped synchronization attempts for 30 days and failed attempts for 180 days by default; make every period and chunk size configuration-driven.
- Never archive pending/running work. Archive terminal rows transactionally into minute-level tenant/account/operation/status summaries before deleting technical attempts.
- Make synchronization history and reporting read the union of live attempts and durable summaries so retention does not alter merchant-visible totals.
- Defer PostgreSQL partitioning until logged live/archive relation sizes and cleanup duration demonstrate that the simpler indexed tables are insufficient.

## 2026-08-20 — Template-driven Trendyol bulk publication

- Store reusable Trendyol category, brand, attribute, and marketplace defaults in tenant-scoped templates, optionally restricted to one central catalog category.
- Validate a product as one unit before dispatching any of its missing variants. Invalid products are reported independently and cannot prevent valid products from entering the queue.
- Treat existing account/variant listings as completed mapping boundaries during bulk submission. Corrected blocked products may be resubmitted without recreating already linked listings.
- Keep Trendyol provider identifiers inside template/listing metadata; the central product and variant UUIDs remain canonical identity.

## 2026-08-20 — Publication deduplication consistency

- Return a provider-neutral typed publication outcome (`queued`, `already_active`, `skipped`, `invalid`) from every publication entry point.
- Keep the PostgreSQL partial unique index on active entity operations as the concurrency boundary. Perform creation in a savepoint so a uniqueness race can be rolled back locally and translated into an `already_active` result even inside a wider transaction.
- Dispatch publication jobs only when the synchronization operation was newly created.

## 2026-08-20 — Channel-capability-aware order statuses

- Do not claim a shipment transition unless the connected provider can receive a distinct shipment status. Core WooCommerce therefore omits central `shipped` by default instead of mapping it incorrectly to `processing`.
- Allow WooCommerce shipment plugins through an account-level provider status code stored under `settings.order_status_mappings.shipped`. Keep this adapter configuration outside encrypted credentials and outside the order domain.
- Present the central label and exact provider code together, and reject unsupported mappings before queueing or mutating central state.

## 2026-08-20 — Test isolation and frontend loading

- Define reusable feature-test builders once in `tests/Pest.php`; individual feature files must not rely on declarations loaded from another test file.
- Keep the current dependency set. The repository has no parallel Pest runner, so independent per-file execution is the enforceable isolation check until parallel support is intentionally introduced.
- Resolve Inertia pages with lazy Vite imports. Enforce a 400 KB uncompressed application-entry budget in the build; this threshold accommodates the shared React/Inertia runtime while route screens remain separate on-demand chunks.

## 2026-08-20 — Organization settings data ownership

- Store personal name and login email on the authenticated user; store commercial, contact, address, and tax identity details on the tenant so the data follows the organization rather than one member.
- Restrict company mutations to owner/admin roles through the existing tenant policy. Membership management retains its stricter role rules.
- Encrypt the Turkish national identity number with Laravel's encrypted model cast and never expose it outside the authorized settings response.
- Derive the current 14-day trial presentation from the tenant creation date until a dedicated billing lifecycle is introduced; do not imply that e-invoice or add-on purchasing is implemented.

## 2026-08-20 — SaaS package and subscription management

- Model package definitions, entitlements, and tenant subscriptions as relational records instead of expanding tenant JSON. Prices use integer minor units and ISO currency codes.
- Keep one subscription row per tenant and preserve lifecycle dates, billing cycle, cancellation timestamp, and internal administrative notes. Package changes and legacy tenant plan/status synchronization are transactional.
- Keep feature identifiers provider-neutral (`catalog`, `marketplaces`, `users`, `reports`, `bulk_operations`, `priority_support`) so future channels do not require package-schema changes.
- Seed the four existing compatibility plans and backfill every tenant. Plans with subscriptions cannot be deleted; administrators deactivate them instead.
- This is manual SaaS administration, not payment collection. Gateway checkout, invoices, renewals, proration, and automated dunning remain outside the authorized scope.

## 2026-08-20 — Integration categories

- Present integrations in separate marketplace, e-commerce, and shipping navigation categories; WooCommerce belongs to e-commerce and Trendyol belongs to marketplace.
- Reuse the global channel `type` as the category discriminator (`marketplace`, `storefront`, and future `shipping`) so tenant-owned account and core channel contracts remain unchanged.
- Keep shipping as an empty, explicit category until a shipping integration is requested; no carrier domain or provider is introduced by this navigation change.

## 2026-08-20 — Hepsiburada and Amazon marketplace discovery

- Add Hepsiburada and Amazon as global marketplace references so the marketplace catalog reflects the planned providers and can reuse the existing logos.
- Keep account creation disabled until each provider has a complete credential and connector boundary. A visible “coming soon” state must not imply that synchronization or connection testing works.
- Model Amazon against the current Selling Partner API: Turkey uses the Europe endpoint and a multi-tenant public SaaS must complete the LWA OAuth seller-authorization flow. Do not replace this with a misleading static API-key form.
- Keep Hepsiburada implementation behind its future adapter and validate its merchant authentication against the official developer portal before enabling credentials or network jobs.

## 2026-08-20 — Hepsiburada connection foundation

- Authenticate the Hepsiburada merchant listing API with the provider-issued merchant ID, Basic Auth username/password, and mandatory `{merchantId} - MarketplaceSaaS` user agent.
- Use the production and SIT listing hosts from configuration and test credentials with a bounded, read-only one-item listing request.
- Encrypt all credentials through the existing channel-account encrypted cast, expose only the merchant ID and masked username, and dispatch connection tests through the shared retryable sync job.
- Keep product, inventory, price, and order operations explicitly unsupported until their dedicated mapping and idempotency phases are implemented.
