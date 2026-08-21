<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

final class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->city().' Warehouse',
            'code' => fake()->unique()->bothify('WH-###'),
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
