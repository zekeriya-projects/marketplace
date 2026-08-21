<?php

declare(strict_types=1);

use App\Models\InventoryMovement;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoStoreSeeder;

it('seeds an idempotent tenant scoped demo catalog and inventory ledger', function (): void {
    $this->seed(DemoStoreSeeder::class);
    $this->seed(DemoStoreSeeder::class);

    $tenant = Tenant::query()->where('slug', 'demo-magaza')->sole();
    $user = User::query()->where('email', 'demo@marketplace.test')->sole();

    expect($user->active_tenant_id)->toBe($tenant->id)
        ->and($user->roleFor($tenant)?->value)->toBe('owner')
        ->and($tenant->categories()->count())->toBe(3)
        ->and($tenant->brands()->count())->toBe(2)
        ->and($tenant->products()->count())->toBe(2)
        ->and($tenant->products()->withCount('variants')->get()->sum('variants_count'))->toBe(3)
        ->and((int) $tenant->warehouses()->sole()->inventoryItems()->sum('quantity'))->toBe(47)
        ->and(InventoryMovement::query()->where('tenant_id', $tenant->id)->count())->toBe(3);
});
