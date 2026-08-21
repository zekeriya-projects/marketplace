# Magenty Reference Audit

The static pages under `public/Magenty` were reviewed as a product and UX reference. They are not shipped as application pages and no saved third-party runtime assets are used by the SaaS.

## Adapted into the MVP

| Magenty area | Marketplace SaaS implementation |
| --- | --- |
| Anasayfa | Tenant dashboard and operational summaries |
| Ürünler / Ürün detayı | Rich central catalog list, product detail, variants and channel state |
| Ürün varyant listesi / Varyantlar | Variant-based SKU, barcode, price and inventory model |
| Markalar / Kategoriler | Tenant-owned reference screens and Trendyol matching suggestions |
| Pazaryeri anasayfa | Channel listing dashboard with status counts, filters and stock/price visibility |
| Pazaryerleri | WooCommerce and Trendyol account management |
| Tüm satışlar / Sipariş hareketleri | Central order list and order detail/history |
| İşlemler / Hatalar | One synchronization center with summary cards, all/error tabs and retry actions |

The product-to-marketplace flow asks only for information the connector cannot infer. Main product brand/category, variant data, VAT, desi and the first usable HTTPS image are proposed automatically. Channel-specific required attributes remain explicit.

## Intentionally not copied

Barcode template design, cargo integrations, advanced exchange-rate/pricing rules, Hepsiburada, N11, Amazon, e-invoice/accounting, subscription billing and advanced reporting are outside the documented MVP.

## Design principles adopted

- Keep the SaaS catalog as the source of truth and channel data as mappings.
- Use compact status summaries before large tables.
- Prefer server-side filters and pagination.
- Keep errors beside their operation and expose safe retry actions.
- Prefill marketplace data from the central product where possible.

## UI implementation notes

- Product, inventory, category, brand, marketplace listing and synchronization lists use the shared data-table visual language with server-side pagination and filters where the dataset can grow.
- Warehouse creation is performed in a modal; inventory terminology is Turkish throughout the main inventory screen.
- Brand and category rows expose marketplace mappings and a compact `+` mapping action. Trendyol reference values can be queried from its API, while a manual ID/name fallback supports other connectors without coupling catalog models to a provider.
- Product rows expose existing channel badges and a `+` action for connecting an already-existing marketplace product/variant.
- Marketplace accounts use provider cards, connection states and a guided integration modal instead of a configuration table.
