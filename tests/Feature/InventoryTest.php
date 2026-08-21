<?php

declare(strict_types=1);

use App\Domain\Inventory\Actions\AdjustInventory;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function inventoryFixture(Tenant $tenant): array
{
    $warehouse = Warehouse::factory()->for($tenant)->create(['is_default' => true]);
    $product = Product::factory()->for($tenant)->create();
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id]);

    return [$warehouse, $variant];
}

it('creates a tenant scoped warehouse and makes the first one default', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant, TenantRole::Operator);

    $this->actingAs($user)->post('/warehouses', ['name' => 'Main Warehouse', 'code' => 'main'])->assertRedirect();

    $warehouse = Warehouse::query()->sole();
    expect($warehouse->tenant_id)->toBe($tenant->id)
        ->and($warehouse->code)->toBe('MAIN')
        ->and($warehouse->is_default)->toBeTrue();
});

it('records every successful adjustment as a balanced movement', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant, TenantRole::Operator);
    [$warehouse, $variant] = inventoryFixture($tenant);

    $this->actingAs($user)->post("/inventory/{$variant->id}/warehouses/{$warehouse->id}/adjustments", [
        'quantity_delta' => 12,
        'note' => 'Opening count',
    ])->assertRedirect();

    $item = InventoryItem::query()->sole();
    $movement = InventoryMovement::query()->sole();
    expect($item->quantity)->toBe(12)
        ->and($item->reserved_quantity)->toBe(0)
        ->and($movement->quantity_delta)->toBe(12)
        ->and($movement->quantity_before)->toBe(0)
        ->and($movement->quantity_after)->toBe(12)
        ->and($movement->created_by_user_id)->toBe($user->id)
        ->and($movement->note)->toBe('Opening count');
});

it('serializes successive stock mutations through the current locked snapshot', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant);
    [$warehouse, $variant] = inventoryFixture($tenant);
    $action = app(AdjustInventory::class);

    $action->execute($tenant, $warehouse, $variant, 10, 'First', $user);
    $action->execute($tenant, $warehouse, $variant, -3, 'Second', $user);
    $action->execute($tenant, $warehouse, $variant, 5, 'Third', $user);

    expect(InventoryItem::query()->sole()->quantity)->toBe(12)
        ->and(InventoryMovement::query()->orderBy('created_at')->get()->map->only(['quantity_before', 'quantity_delta', 'quantity_after'])->all())
        ->toBe([
            ['quantity_before' => 0, 'quantity_delta' => 10, 'quantity_after' => 10],
            ['quantity_before' => 10, 'quantity_delta' => -3, 'quantity_after' => 7],
            ['quantity_before' => 7, 'quantity_delta' => 5, 'quantity_after' => 12],
        ]);
});

it('rejects negative stock and rolls back the movement', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant);
    [$warehouse, $variant] = inventoryFixture($tenant);

    expect(fn () => app(AdjustInventory::class)->execute($tenant, $warehouse, $variant, -1, 'Invalid', $user))
        ->toThrow(ValidationException::class);

    expect(InventoryItem::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('prevents viewers from creating warehouses or adjusting stock', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant, TenantRole::Viewer);
    [$warehouse, $variant] = inventoryFixture($tenant);

    $this->actingAs($user)->get('/inventory')->assertOk();
    $this->actingAs($user)->post('/warehouses', ['name' => 'Blocked', 'code' => 'BLOCKED'])->assertForbidden();
    $this->actingAs($user)->post("/inventory/{$variant->id}/warehouses/{$warehouse->id}/adjustments", [
        'quantity_delta' => 1,
        'note' => 'Blocked',
    ])->assertForbidden();
    expect(InventoryMovement::query()->count())->toBe(0);
});

it('prevents cross tenant warehouse and variant mutations', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $user = tenantMember($tenant);
    [$warehouse, $variant] = inventoryFixture($tenant);
    [$otherWarehouse, $otherVariant] = inventoryFixture($otherTenant);

    $this->actingAs($user)->post("/inventory/{$variant->id}/warehouses/{$otherWarehouse->id}/adjustments", [
        'quantity_delta' => 1,
        'note' => 'Cross tenant warehouse',
    ])->assertForbidden();
    $this->actingAs($user)->post("/inventory/{$otherVariant->id}/warehouses/{$warehouse->id}/adjustments", [
        'quantity_delta' => 1,
        'note' => 'Cross tenant variant',
    ])->assertSessionHasErrors('warehouse_id');
    expect(InventoryMovement::query()->count())->toBe(0);
});

it('lists only active tenant variants and their warehouse quantity', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $user = tenantMember($tenant);
    [$warehouse, $variant] = inventoryFixture($tenant);
    inventoryFixture($otherTenant);
    app(AdjustInventory::class)->execute($tenant, $warehouse, $variant, 8, 'Count', $user);

    $this->actingAs($user)->get("/inventory?warehouse={$warehouse->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Inventory/Index')
        ->has('warehouses', 1)
        ->has('variants.data', 1)
        ->where('variants.data.0.id', $variant->id)
        ->where('variants.data.0.quantity', 8));
});

it('keeps inventory movements immutable', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant);
    [$warehouse, $variant] = inventoryFixture($tenant);
    $movement = app(AdjustInventory::class)->execute($tenant, $warehouse, $variant, 2, 'Count', $user);

    expect(fn () => $movement->update(['note' => 'Rewritten']))->toThrow(LogicException::class)
        ->and(fn () => $movement->delete())->toThrow(LogicException::class);
});
