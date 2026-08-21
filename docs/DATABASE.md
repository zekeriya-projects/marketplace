# DATABASE.md

## 1. General Rules

Database: PostgreSQL.

The schema is shared across all tenants.

Every tenant-owned table includes `tenant_id`.

Prefer UUIDv7 identifiers where practical.

Every foreign key should be explicit.

Use timestamps.

Use soft deletes only where business recovery/audit requirements justify them.

---

## 2. Tenancy

### tenants

```text
id
name
slug
status
created_at
updated_at
```

Constraints:

- slug unique

### users

Use Laravel-compatible user fields plus project requirements.

```text
id
name
email
email_verified_at
password
remember_token
created_at
updated_at
```

Constraints:

- email unique

### tenant_user

```text
tenant_id
user_id
role
created_at
updated_at
```

Constraints:

- unique(tenant_id, user_id)

Initial roles:

```text
owner
admin
operator
viewer
```

Do not build a complex permission engine in the first migration unless required.

---

## 3. Channels

### channels

Global reference table.

```text
id
code
name
type
is_active
created_at
updated_at
```

Examples:

```text
woocommerce
trendyol
```

Constraints:

- code unique

### channel_accounts

Tenant connection to a channel.

```text
id
tenant_id
channel_id
name
credentials_encrypted
settings
status
last_connected_at
created_at
updated_at
```

Suggested status:

```text
pending
active
error
disabled
```

Constraints:

- index(tenant_id, channel_id)

Never expose `credentials_encrypted` through normal serialization.

---

## 4. Catalog

### products

```text
id
tenant_id
name
description
brand
status
created_at
updated_at
```

Suggested status:

```text
draft
active
archived
```

Indexes:

- tenant_id
- (tenant_id, name)

### product_variants

```text
id
tenant_id
product_id
sku
barcode
name
base_price_amount
currency
status
created_at
updated_at
```

Constraints:

- foreign key product_id -> products
- optional unique(tenant_id, sku) where business rules allow
- index(tenant_id, barcode)
- index(product_id)

Prices use integer minor units.

Example:

```text
199900 = 1,999.00 TRY
```

---

## 5. Warehouses / Inventory

### warehouses

```text
id
tenant_id
name
code
is_default
is_active
created_at
updated_at
```

Constraints:

- unique(tenant_id, code)

### inventory_items

Current quantity snapshot.

```text
id
tenant_id
warehouse_id
product_variant_id
quantity
reserved_quantity
created_at
updated_at
```

Constraints:

- unique(tenant_id, warehouse_id, product_variant_id)

Available quantity concept:

```text
available = quantity - reserved_quantity
```

Whether reservation is used in the first feature can be deferred, but the schema can preserve the concept if implementation remains simple.

### inventory_movements

Immutable stock change ledger.

```text
id
tenant_id
warehouse_id
product_variant_id
order_id nullable
type
quantity_delta
quantity_before
quantity_after
reference_type nullable
reference_id nullable
note nullable
created_by_user_id nullable
created_at
```

Suggested movement types:

```text
import
manual_adjustment
sale
cancellation
return
correction
```

Do not routinely update/delete historical inventory movements.

---

## 6. Channel Listings

### channel_listings

Maps an internal sellable variant/product to an external channel representation.

```text
id
tenant_id
channel_account_id
product_id
product_variant_id nullable
external_product_id nullable
external_variant_id nullable
external_sku nullable
external_barcode nullable
status
channel_price_amount nullable
currency nullable
published_at nullable
last_synced_at nullable
metadata nullable
created_at
updated_at
```

Suggested status:

```text
unmapped
pending
active
rejected
disabled
error
```

Indexes:

- tenant_id
- channel_account_id
- product_variant_id
- external_product_id
- external_sku

Potential uniqueness must be tailored to provider semantics.

At minimum avoid duplicate mapping of the exact same external entity under a channel account.

---

## 7. Orders

### orders

```text
id
tenant_id
channel_account_id
external_order_id
external_order_number
status
external_status
currency
subtotal_amount
discount_amount
shipping_amount
tax_amount
total_amount
customer_snapshot
shipping_address_snapshot
billing_address_snapshot nullable
ordered_at
imported_at
inventory_applied_at nullable
created_at
updated_at
```

Constraints:

- unique(channel_account_id, external_order_id)
- index(tenant_id, ordered_at)
- index(tenant_id, status)
- index(tenant_id, currency, ordered_at, channel_account_id) for detailed reporting

Customer/address data is stored as a snapshot because external order history must remain historically accurate.

### order_items

```text
id
tenant_id
order_id
product_variant_id nullable
channel_listing_id nullable
external_item_id nullable
external_product_id nullable
external_sku nullable
external_barcode nullable
name
quantity
unit_price_amount
discount_amount
tax_amount
total_amount
mapping_status
created_at
updated_at
```

Suggested mapping status:

```text
mapped
unmapped
ambiguous
```

An unmapped order item must still be persisted.

### order_inventory_allocations

Persists the idempotent inventory lifecycle independently from replaceable order-item snapshots.

```text
id
tenant_id
order_id
warehouse_id
product_variant_id
ordered_quantity
reserved_quantity
sold_quantity
cancelled_quantity
returned_quantity
released_quantity
created_at
updated_at
```

Constraints:

- unique(order_id, warehouse_id, product_variant_id)
- ordered_quantity > 0
- reserved_quantity + sold_quantity + released_quantity <= ordered_quantity
- cancelled_quantity + returned_quantity <= sold_quantity
- composite tenant foreign keys protect order, warehouse, and variant agreement

Reporting also uses `order_items(order_id, product_variant_id)` and an expression index on `inventory_items(tenant_id, quantity - reserved_quantity)` so product and low-stock aggregates remain bounded.

`pending` and `confirmed` quantities remain reserved. Processing consumes the reservation as a sale. Cancellation/return releases an open reservation or compensates the uncompensated sold quantity.

---

## 8. Synchronization

### sync_operations

Tracks a logical synchronization action.

```text
id
tenant_id
channel_account_id
operation
entity_type
entity_id nullable
status
attempt
started_at nullable
finished_at nullable
error_category nullable
error_code nullable
safe_error_message nullable
context nullable
created_at
updated_at
```

Operations might include:

```text
connection_test
product_import
listing_publish
inventory_push
price_push
order_pull
```

Statuses:

```text
pending
running
succeeded
failed
skipped
```

Indexes:

- (tenant_id, created_at)
- (channel_account_id, status)
- (entity_type, entity_id)

Do not store API secrets inside `context`.

### trendyol_listing_templates

Tenant-scoped reusable publication mappings. They store an optional compatible central `category_id`, Trendyol category/brand identifiers, selected and required attribute identifiers, and marketplace defaults. Provider IDs remain mapping metadata and are never used as internal primary keys. `(tenant_id, name)` is unique and `(tenant_id, category_id)` supports compatible bulk selection.

### sync_operation_archives

Stores durable minute-level synchronization summaries after disposable terminal attempts expire. The unique tenant/account/operation/minute/status key makes chunked archival restartable. Live `pending` and `running` operations are never archived.

Defaults are configured in `config/sync.php`: succeeded/skipped 30 days, failed 180 days, and 1,000 rows per transaction. Environment overrides use `SYNC_SUCCEEDED_RETENTION_DAYS`, `SYNC_SKIPPED_RETENTION_DAYS`, `SYNC_FAILED_RETENTION_DAYS`, and `SYNC_CLEANUP_CHUNK_SIZE`.

---

## 9. External Event Deduplication

Optional but recommended when implementing webhooks.

### channel_events

```text
id
tenant_id
channel_account_id
external_event_id
event_type
status
payload_hash nullable
received_at
processed_at nullable
created_at
updated_at
```

Constraint:

- unique(channel_account_id, external_event_id)

If a provider has no event ID, connector-specific dedupe strategy is required.

---

## 10. Sync Cursors

For polling-based imports.

### channel_sync_states

```text
id
tenant_id
channel_account_id
resource_type
cursor nullable
last_synced_from nullable
last_synced_to nullable
metadata nullable
created_at
updated_at
```

Constraint:

- unique(channel_account_id, resource_type)

Examples resource_type:

```text
products
orders
```

---

## 11. Suggested Entity Relationships

```text
Tenant
 ├── Users
 ├── Products
 │    └── ProductVariants
 │          ├── InventoryItems
 │          └── ChannelListings
 │
 ├── Warehouses
 │    └── InventoryItems
 │
 ├── ChannelAccounts
 │    ├── ChannelListings
 │    ├── Orders
 │    └── SyncOperations
 │
 └── Orders
      └── OrderItems
```

---

## 12. Important Constraints

### Tenant isolation

A variant's tenant must match its product tenant.

An inventory item's tenant must match warehouse and variant tenant.

An order item's tenant must match order tenant.

Laravel service logic and tests must enforce this even where PostgreSQL cannot easily express cross-table tenant equality with simple foreign keys.

### Order idempotency

```text
unique(channel_account_id, external_order_id)
```

is non-negotiable.

### Inventory uniqueness

Exactly one current inventory row per:

```text
tenant + warehouse + variant
```

### Money

All monetary columns are integer minor units.

Never use float/double for money.

---

## 13. Deferred Tables

Do not create these before needed:

- subscriptions
- plans
- invoices
- shipments
- returns
- suppliers
- purchase_orders
- marketplace_campaigns
- accounting_documents
- competitor_prices
