<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

final class ChannelAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'channel_id' => fn () => Channel::query()->firstOrFail()->id,
            'name' => fake()->company(),
            'settings' => [],
        ];
    }
}
