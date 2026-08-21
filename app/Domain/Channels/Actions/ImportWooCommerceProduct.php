<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Catalog\Enums\CatalogStatus;
use App\Domain\Catalog\Support\MinorUnits;
use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ImportWooCommerceProduct
{
    /** @param array<string, mixed> $payload @param list<array<string, mixed>> $variations */
    public function execute(ChannelAccount $account, array $payload, array $variations = []): Product
    {
        $externalProductId = isset($payload['id']) ? (string) $payload['id'] : '';
        $name = trim((string) ($payload['name'] ?? ''));
        $type = (string) ($payload['type'] ?? 'simple');
        if ($externalProductId === '' || $name === '' || ! in_array($type, ['simple', 'variable'], true)) {
            throw new InvalidArgumentException('WooCommerce product payload is not importable.');
        }

        $sources = $type === 'variable' ? $variations : [$payload];
        if ($sources === []) {
            throw new InvalidArgumentException('WooCommerce variable product has no variations.');
        }

        return DB::transaction(function () use ($account, $payload, $externalProductId, $name, $sources, $type): Product {
            $existingListing = ChannelListing::query()->where('tenant_id', $account->tenant_id)->where('channel_account_id', $account->id)->where('external_product_id', $externalProductId)->first();
            $product = $existingListing?->product ?? Product::query()->create([
                'tenant_id' => $account->tenant_id,
                'name' => $name,
                'status' => CatalogStatus::Draft,
            ]);
            $product->update([
                'name' => $name,
                'brand' => data_get($payload, 'brands.0.name'),
                'description' => $this->plainText((string) ($payload['description'] ?? $payload['short_description'] ?? '')),
                'status' => ($payload['status'] ?? null) === 'publish' ? CatalogStatus::Active : CatalogStatus::Draft,
            ]);

            foreach ($sources as $source) {
                $externalVariantId = $type === 'variable' ? (string) ($source['id'] ?? '') : null;
                if ($type === 'variable' && $externalVariantId === '') {
                    continue;
                }
                $listing = ChannelListing::query()->where('channel_account_id', $account->id)->where('external_product_id', $externalProductId)->where('external_variant_id', $externalVariantId)->first();
                $variant = $listing?->variant ?? new ProductVariant(['tenant_id' => $account->tenant_id, 'product_id' => $product->id]);
                $sku = $this->availableSku($account->tenant_id, trim((string) ($source['sku'] ?? '')), $variant->exists ? $variant->id : null);
                $price = trim((string) ($source['price'] ?? $source['regular_price'] ?? '0'));
                $variant->fill([
                    'name' => $type === 'variable' ? $this->variationName($source, $name) : 'Default',
                    'sku' => $sku,
                    'barcode' => ($source['global_unique_id'] ?? '') !== '' ? (string) $source['global_unique_id'] : null,
                    'base_price_amount' => MinorUnits::fromDecimal($price === '' ? '0' : $price),
                    'currency' => strtoupper((string) data_get($account->settings, 'currency', 'TRY')),
                    'status' => ($source['status'] ?? $payload['status'] ?? null) === 'publish' ? CatalogStatus::Active : CatalogStatus::Draft,
                ])->save();

                ChannelListing::query()->updateOrCreate(
                    ['channel_account_id' => $account->id, 'external_product_id' => $externalProductId, 'external_variant_id' => $externalVariantId],
                    ['tenant_id' => $account->tenant_id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'external_sku' => ($source['sku'] ?? '') ?: null, 'external_barcode' => ($source['global_unique_id'] ?? '') ?: null, 'status' => ChannelListingStatus::Active, 'channel_price_amount' => null, 'currency' => $variant->currency, 'last_synced_at' => now(), 'metadata' => ['product_type' => $type]],
                );
            }

            return $product->refresh();
        }, 3);
    }

    private function availableSku(string $tenantId, string $sku, ?string $exceptId): ?string
    {
        if ($sku === '') {
            return null;
        }
        $query = ProductVariant::query()->where('tenant_id', $tenantId)->where('sku', $sku);
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->exists() ? null : $sku;
    }

    /** @param array<string, mixed> $source */
    private function variationName(array $source, string $fallback): string
    {
        $parts = collect($source['attributes'] ?? [])->map(fn (array $attribute): string => trim((string) ($attribute['name'] ?? '')).': '.trim((string) ($attribute['option'] ?? '')))->filter(fn (string $value): bool => $value !== ': ');

        return $parts->isEmpty() ? $fallback : $parts->implode(' / ');
    }

    private function plainText(string $html): ?string
    {
        $value = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' ? null : $value;
    }
}
