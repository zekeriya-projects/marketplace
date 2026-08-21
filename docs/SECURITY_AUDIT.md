# MVP Security and Data-Isolation Audit

Reviewed: 2026-08-11

## Authorization and tenancy

- All business routes require authentication and resolved active-tenant middleware.
- Browser-provided tenant IDs are not accepted; tenant context comes from authenticated membership.
- Product, inventory, order, channel-account, membership and organization mutations use policies/Form Requests.
- External lookup and manual synchronization endpoints authorize the account and verify provider/status.
- Tenant-owned queries and composite database foreign keys prevent cross-tenant relationships.
- Owner/admin/operator/viewer behavior and cross-tenant access are covered by feature tests.

## Credentials and logging

- Channel credentials use Laravel's encrypted array cast, are hidden from serialization, and are never returned after save.
- WooCommerce uses HTTPS Basic Auth with SSRF/DNS protections. Trendyol uses configured provider hosts, Basic Auth and required headers.
- Queue payloads contain internal identifiers, not credentials. Workers reload encrypted credentials at execution time.
- Synchronization logs store normalized safe errors; raw provider responses are not persisted.
- Structured operational logs contain only internal identifiers, status, attempts and normalized error codes.

## Abuse and operational controls

- Login and registration have identity/IP rate limits.
- Trendyol sync/order jobs use a Redis-backed provider limiter; HTTP 429/network/5xx failures use bounded backoff.
- Active sync operations are deduplicated at application and database levels where repeated writes are unsafe.
- Server-side pagination exists for products, inventory, orders and sync history. Channel accounts and listing status remain bounded pilot collections.
- Query indexes cover tenant searches, order filters, listing SKU/barcode mapping, sync status/history and idempotency keys.

## Deferred risks

- Automated cancellation/return inventory compensation is outside the MVP; operators must process it through explicit stock adjustments until a dedicated workflow is implemented.
- Enterprise SSO, audit export, IP allowlisting and a managed WAF are post-pilot controls.
- Infrastructure TLS, database/Redis network isolation, secret-manager policy, backup encryption and alert delivery are deployment responsibilities described in the production runbooks.

