<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Enums\CatalogStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

final class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(3, true),
            'brand' => fake()->optional()->company(),
            'description' => fake()->optional()->paragraph(),
            'status' => CatalogStatus::Draft,
        ];
    }
}
