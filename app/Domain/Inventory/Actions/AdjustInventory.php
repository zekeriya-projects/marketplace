<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\InventoryMovementType;
use App\Events\InventoryChanged;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdjustInventory
{
    public function execute(Tenant $tenant, Warehouse $warehouse, ProductVariant $variant, int $delta, string $note, User $user): InventoryMovement
    {
        if ($warehouse->tenant_id !== $tenant->getKey() || $variant->tenant_id !== $tenant->getKey()) {
            throw ValidationException::withMessages(['warehouse_id' => 'Warehouse and variant must belong to the active organization.']);
        }

        $movement = DB::transaction(function () use ($tenant, $warehouse, $variant, $delta, $note, $user): InventoryMovement {
            $now = now();
            DB::table('inventory_items')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => 0,
                'reserved_quantity' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $item = InventoryItem::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('warehouse_id', $warehouse->getKey())
                ->where('product_variant_id', $variant->getKey())
                ->lockForUpdate()
                ->sole();

            $quantityAfter = $item->quantity + $delta;
            if ($quantityAfter < $item->reserved_quantity) {
                throw ValidationException::withMessages(['quantity_delta' => 'Adjustment would make available stock negative.']);
            }

            $movement = InventoryMovement::query()->create([
                'tenant_id' => $tenant->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'product_variant_id' => $variant->getKey(),
                'type' => InventoryMovementType::ManualAdjustment,
                'quantity_delta' => $delta,
                'quantity_before' => $item->quantity,
                'quantity_after' => $quantityAfter,
                'note' => $note,
                'created_by_user_id' => $user->getKey(),
            ]);

            $item->update(['quantity' => $quantityAfter]);

            return $movement;
        }, attempts: 3);

        InventoryChanged::dispatch($tenant->getKey(), $variant->getKey());

        return $movement;
    }
}
