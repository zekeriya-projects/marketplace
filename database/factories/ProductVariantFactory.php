<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Enums\CatalogStatus;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

final class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'tenant_id' => fn (array $attributes) => Product::query()->findOrFail($attributes['product_id'])->tenant_id,
            'name' => fake()->words(2, true),
            'sku' => fake()->unique()->bothify('SKU-####-????'),
            'barcode' => fake()->ean13(),
            'base_price_amount' => fake()->numberBetween(1000, 100000),
            'currency' => 'TRY',
            'status' => CatalogStatus::Active,
        ];
    }
}
