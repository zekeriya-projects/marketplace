<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Orders\Enums\OrderStatus;
use App\Models\ChannelAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

final class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel_account_id' => ChannelAccount::factory(),
            'tenant_id' => fn (array $attributes) => ChannelAccount::query()->findOrFail($attributes['channel_account_id'])->tenant_id,
            'external_order_id' => fake()->unique()->uuid(),
            'external_order_number' => fake()->numerify('ORD-######'),
            'status' => OrderStatus::Pending,
            'external_status' => 'pending',
            'currency' => 'TRY',
            'subtotal_amount' => 10000, 'discount_amount' => 0, 'shipping_amount' => 0, 'tax_amount' => 0, 'total_amount' => 10000,
            'customer_snapshot' => ['name' => fake()->name()],
            'shipping_address_snapshot' => ['city' => fake()->city()],
            'billing_address_snapshot' => null,
            'ordered_at' => now(), 'imported_at' => now(),
        ];
    }
}
