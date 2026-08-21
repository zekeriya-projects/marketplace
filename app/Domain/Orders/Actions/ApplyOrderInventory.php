<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Inventory\Enums\InventoryMovementType;
use App\Domain\Orders\Enums\OrderItemMappingStatus;
use App\Domain\Orders\Enums\OrderStatus;
use App\Events\InventoryChanged;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderInventoryAllocation;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ApplyOrderInventory
{
    public function __construct(private readonly CompensateOrderInventory $compensate) {}

    public function execute(Order $order): bool
    {
        $status = Order::query()->where('tenant_id', $order->tenant_id)->whereKey($order->id)->firstOrFail()->status;
        if (in_array($status, [OrderStatus::Cancelled, OrderStatus::Returned], true)) {
            $type = $status === OrderStatus::Cancelled ? InventoryMovementType::Cancellation : InventoryMovementType::Return;

            return $this->compensate->execute($order, $type);
        }

        $reserve = in_array($status, [OrderStatus::Pending, OrderStatus::Confirmed], true);
        $variantIds = DB::transaction(function () use ($order, $reserve): array {
            $locked = Order::query()->where('tenant_id', $order->tenant_id)->lockForUpdate()->findOrFail($order->id);
            if (! $reserve && $locked->inventory_applied_at !== null) {
                return [];
            }
            $items = $locked->items()->where('mapping_status', OrderItemMappingStatus::Mapped)->whereNotNull('product_variant_id')->get();
            if ($items->isEmpty()) {
                $locked->update(['inventory_applied_at' => now()]);

                return [];
            }
            $warehouse = Warehouse::query()->where('tenant_id', $locked->tenant_id)->where('is_default', true)->where('is_active', true)->first();
            if ($warehouse === null) {
                throw new RuntimeException('An active default warehouse is required before mapped orders can reduce inventory.');
            }
            $quantities = $items->groupBy('product_variant_id')->map->sum('quantity');
            $changed = [];
            foreach ($quantities as $variantId => $quantity) {
                $now = now();
                DB::table('inventory_items')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $locked->tenant_id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variantId, 'quantity' => 0, 'reserved_quantity' => 0, 'created_at' => $now, 'updated_at' => $now]);
                $stock = InventoryItem::query()->where('tenant_id', $locked->tenant_id)->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variantId)->lockForUpdate()->sole();
                $allocation = OrderInventoryAllocation::query()
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('order_id', $locked->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_variant_id', $variantId)
                    ->lockForUpdate()
                    ->first();
                if ($allocation !== null && ($allocation->reserved_quantity > 0 || $allocation->sold_quantity > 0 || $allocation->released_quantity > 0)) {
                    if ($reserve || $allocation->sold_quantity > 0 || $allocation->released_quantity > 0) {
                        continue;
                    }
                }

                if ($reserve) {
                    if ($stock->availableQuantity() < (int) $quantity) {
                        throw new RuntimeException('Mapped order inventory is insufficient in the default warehouse.');
                    }
                    $stock->update(['reserved_quantity' => $stock->reserved_quantity + (int) $quantity]);
                    OrderInventoryAllocation::query()->create([
                        'tenant_id' => $locked->tenant_id,
                        'order_id' => $locked->id,
                        'warehouse_id' => $warehouse->id,
                        'product_variant_id' => $variantId,
                        'ordered_quantity' => (int) $quantity,
                        'reserved_quantity' => (int) $quantity,
                        'sold_quantity' => 0,
                    ]);
                    $changed[] = (string) $variantId;

                    continue;
                }

                $saleQuantity = $allocation?->reserved_quantity ?? (int) $quantity;
                $after = $stock->quantity - $saleQuantity;
                $reservedAfter = $stock->reserved_quantity - ($allocation?->reserved_quantity ?? 0);
                if ($after < $reservedAfter || $reservedAfter < 0) {
                    throw new RuntimeException('Mapped order inventory is insufficient in the default warehouse.');
                }
                InventoryMovement::query()->create(['tenant_id' => $locked->tenant_id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variantId, 'type' => InventoryMovementType::Sale, 'quantity_delta' => -$saleQuantity, 'quantity_before' => $stock->quantity, 'quantity_after' => $after, 'reference_type' => $locked->getMorphClass(), 'reference_id' => $locked->id, 'note' => 'Channel order inventory consumption']);
                if ($allocation === null) {
                    OrderInventoryAllocation::query()->create([
                        'tenant_id' => $locked->tenant_id,
                        'order_id' => $locked->id,
                        'warehouse_id' => $warehouse->id,
                        'product_variant_id' => $variantId,
                        'ordered_quantity' => (int) $quantity,
                        'reserved_quantity' => 0,
                        'sold_quantity' => $saleQuantity,
                    ]);
                } else {
                    $allocation->update(['reserved_quantity' => 0, 'sold_quantity' => $allocation->sold_quantity + $saleQuantity]);
                }
                $stock->update(['quantity' => $after, 'reserved_quantity' => $reservedAfter]);
                $changed[] = (string) $variantId;
            }
            if (! $reserve) {
                $locked->update(['inventory_applied_at' => now()]);
            }

            return array_values(array_unique($changed));
        }, 3);

        foreach ($variantIds as $variantId) {
            InventoryChanged::dispatch($order->tenant_id, (string) $variantId, $order->channel_account_id);
        }

        return $variantIds !== [];
    }
}
