<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Support\MinorUnits;
use App\Events\VariantPriceChanged;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveProduct
{
    public function create(Tenant $tenant, array $attributes): Product
    {
        return DB::transaction(function () use ($tenant, $attributes): Product {
            $this->ensureSkusAreAvailable($tenant, $attributes['variants']);
            $product = $tenant->products()->create($this->productValues($attributes));
            $this->saveVariants($tenant, $product, $attributes['variants']);

            return $product;
        });
    }

    public function update(Tenant $tenant, Product $product, array $attributes): Product
    {
        $changedPrices = [];
        $saved = DB::transaction(function () use ($tenant, $product, $attributes, &$changedPrices): Product {
            $this->ensureVariantsBelongToProduct($product, $attributes['variants']);
            $this->ensureSkusAreAvailable($tenant, $attributes['variants']);
            $product->update($this->productValues($attributes));
            $this->saveVariants($tenant, $product, $attributes['variants'], $changedPrices);

            return $product;
        });

        foreach ($changedPrices as $variantId) {
            VariantPriceChanged::dispatch($tenant->getKey(), $variantId);
        }

        return $saved;
    }

    private function saveVariants(Tenant $tenant, Product $product, array $variants, array &$changedPrices = []): void
    {
        foreach ($variants as $variant) {
            $values = [
                ...Arr::only($variant, ['name', 'sku', 'barcode', 'currency', 'status', 'variant_template_id', 'option_values']),
                'tenant_id' => $tenant->getKey(),
                'base_price_amount' => MinorUnits::fromDecimal($variant['base_price']),
            ];

            if (isset($variant['id'])) {
                $model = $product->variants()->whereKey($variant['id'])->firstOrFail();
                $oldPrice = $model->base_price_amount;
                $model->update($values);
                if ($oldPrice !== $model->base_price_amount) {
                    $changedPrices[] = $model->id;
                }
            } else {
                $product->variants()->create($values);
            }
        }
    }

    private function ensureVariantsBelongToProduct(Product $product, array $variants): void
    {
        $ids = collect($variants)->pluck('id')->filter()->values();

        if ($ids->isNotEmpty() && $product->variants()->whereKey($ids)->count() !== $ids->count()) {
            throw ValidationException::withMessages(['variants' => 'One or more variants do not belong to this product.']);
        }
    }

    private function ensureSkusAreAvailable(Tenant $tenant, array $variants): void
    {
        foreach ($variants as $index => $variant) {
            if (! isset($variant['sku'])) {
                continue;
            }

            $query = $tenant->hasMany(ProductVariant::class)->where('sku', $variant['sku']);
            if (isset($variant['id'])) {
                $query->whereKeyNot($variant['id']);
            }

            if ($query->exists()) {
                throw ValidationException::withMessages(["variants.$index.sku" => 'This SKU is already in use in this organization.']);
            }
        }
    }

    private function productValues(array $attributes): array
    {
        $values = Arr::except($attributes, ['variants', 'product_type', 'images', 'compare_at_price', 'purchase_price']);
        $values['compare_at_price_amount'] = filled($attributes['compare_at_price'] ?? null) ? MinorUnits::fromDecimal($attributes['compare_at_price']) : null;
        $values['purchase_price_amount'] = filled($attributes['purchase_price'] ?? null) ? MinorUnits::fromDecimal($attributes['purchase_price']) : null;

        return $values;
    }
}
