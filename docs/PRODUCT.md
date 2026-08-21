# PRODUCT.md

## 1. Product Summary

Marketplace SaaS is a multi-tenant platform that lets merchants manage multiple e-commerce channels from one central panel.

Initial supported channels:

- WooCommerce
- Trendyol

The system centralizes:

- catalog
- variants
- inventory
- channel listings
- prices
- orders
- synchronization history

The long-term product vision is to become the operational hub between a merchant's own web store and external marketplaces.

---

## 2. Primary User

The MVP targets Turkish merchants that:

- sell on Trendyol
- operate or plan to operate a WooCommerce store
- manually maintain stock and price in multiple systems
- need central order visibility
- want to move marketplace catalog data to their own web store
- want to avoid stock inconsistencies between sales channels

---

## 3. Core Value Proposition

A merchant should be able to:

1. Create an organization.
2. Connect WooCommerce.
3. Import products.
4. Normalize products and variants into the SaaS catalog.
5. Connect Trendyol.
6. Publish or map products to Trendyol.
7. Receive orders from both channels.
8. Update central stock after a sale.
9. Synchronize the resulting stock to connected channels.
10. Review failures from a synchronization log.

---

## 4. Product Principles

### Central ownership

The platform owns the canonical internal representation of product, variant and inventory after import.

### Channel independence

WooCommerce is a channel, not the system database.

Trendyol is a channel, not the system database.

### Safe synchronization

A synchronization failure must not silently corrupt internal inventory.

### Traceability

The merchant should be able to understand:

- what was synchronized
- when it happened
- which channel was involved
- whether it succeeded
- why it failed

### Progressive capability

The MVP stays intentionally small and establishes the foundation for future marketplaces.

---

## 5. MVP User Stories

### Organization

As a merchant, I can create and access my organization.

As an organization owner, I can invite or add users.

As an organization owner, I can control user roles.

### WooCommerce

As a merchant, I can add WooCommerce API credentials.

As a merchant, I can test the connection.

As a merchant, I can import products and variants.

As a merchant, products created or updated in the SaaS are automatically published to active WooCommerce accounts.

As a merchant, I can see whether a WooCommerce item is mapped to an internal product.

As a merchant, I can push stock changes to WooCommerce.

As a merchant, I can push price changes to WooCommerce.

As a merchant, I can import WooCommerce orders.

### Trendyol

As a merchant, I can add Trendyol seller credentials.

As a merchant, I can test the connection.

As a merchant, I can map an internal item to an existing Trendyol listing when supported by the integration flow.

As a merchant, I can publish eligible products to Trendyol.

As a merchant, I can push stock updates.

As a merchant, I can push price updates.

As a merchant, I can import Trendyol orders.

### Catalog

As a merchant, I can view products.

As a merchant, I can view variants.

As a merchant, I can search by product name, SKU or barcode.

As a merchant, I can inspect channel listing state.

### Inventory

As a merchant, I can view stock per variant.

As a merchant, I can manually adjust stock with a reason.

As a merchant, I can inspect stock movements.

### Orders

As a merchant, I can see orders from all connected channels in one list.

As a merchant, I can filter by channel and status.

As a merchant, I can inspect order items and external order references.

### Synchronization

As a merchant, I can see synchronization attempts.

As a merchant, I can see failed operations.

As a merchant, I can retry retryable failed operations.

---

## 6. MVP Screens

```text
/auth/login
/dashboard

/products
/products/{id}

/inventory
/inventory/{variant}

/orders
/orders/{id}

/channels
/channels/woocommerce/{account}
/channels/trendyol/{account}

/sync
/settings/organization
/settings/users
```

Routes are conceptual and may be adjusted to Laravel/Inertia conventions.

---

## 7. Dashboard MVP

Show useful operational information, not vanity metrics.

Suggested cards:

- total active products
- variants with low/out-of-stock inventory
- orders today
- failed synchronizations
- connected channel accounts

Suggested recent activity:

- recent orders
- recent synchronization failures

---

## 8. Product Import

WooCommerce is the first catalog import source in the MVP.

Import should:

- fetch products in pages
- normalize products
- normalize variants
- map external IDs
- preserve source metadata required for synchronization
- avoid duplicates on repeated import
- run asynchronously

Repeated imports must update existing mappings instead of blindly creating duplicate products.

Product merge/conflict behavior should initially be conservative.

Do not automatically merge unrelated products solely because their names are similar.

SKU and barcode can be strong matching signals, but destructive merges require explicit rules.

---

## 9. Inventory Behavior

Internal inventory is canonical after product onboarding.

When a channel order is imported and accepted:

1. Resolve the internal variant mapping.
2. Record the order idempotently.
3. Create inventory movement(s).
4. Recalculate available stock.
5. Dispatch inventory synchronization jobs for other active listings.

If an order item cannot be mapped:

- keep the order
- keep the raw external item identifiers
- flag the item as unmapped
- do not guess the inventory mutation

This prevents accidental stock corruption.

---

## 10. Price Behavior

Store a base/internal selling price per variant when needed.

Allow channel listing price overrides.

The MVP does not need a complex pricing rule engine.

Future pricing rules may include:

- channel markup percentage
- marketplace commission
- shipping cost
- minimum profit
- minimum selling price

These are intentionally outside the first implementation.

---

## 11. Order Status

Create normalized internal order states.

Suggested initial enum:

```text
pending
confirmed
processing
shipped
delivered
cancelled
returned
```

Each connector maps provider-specific states to the internal states.

Also preserve the original provider status for debugging and future remapping.

---

## 12. Error Handling

Integration failures should produce safe, actionable messages.

Good:

```text
Trendyol stock update failed: listing not found.
```

Bad:

```text
Request failed.
```

Never display:

- passwords
- API secrets
- bearer tokens
- full authorization headers

---

## 13. Out of Scope for MVP

- subscription payments
- pricing plans
- Turkish e-invoice integration
- shipping carrier integration
- accounting
- marketplace messaging
- returns workflow automation
- campaigns
- bulk supplier XML feeds
- advanced product information management
- advanced analytics
- mobile app
- AI
- ERP functionality
- multi-currency accounting

---

## 14. MVP Success Criteria

The MVP is successful when the following end-to-end flow works reliably:

```text
Connect WooCommerce
        ↓
Import catalog
        ↓
Create canonical products/variants
        ↓
Connect Trendyol
        ↓
Publish or map Trendyol listing
        ↓
Import Trendyol order
        ↓
Reduce central inventory
        ↓
Synchronize new inventory to WooCommerce
        ↓
Display complete sync history
```

A repeated order pull must not duplicate the order or reduce stock twice.

A temporary channel API outage must not destroy internal data.
