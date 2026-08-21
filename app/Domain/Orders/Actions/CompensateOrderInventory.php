<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Inventory\Enums\InventoryMovementType;
use App\Events\InventoryChanged;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderInventoryAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompensateOrderInventory
{
    /**
     * @param  array<string, int>|null  $variantQuantities  Quantities keyed by product variant UUID; null compensates every remaining unit.
     */
    public function execute(Order $order, InventoryMovementType $type, ?array $variantQuantities = null): bool
    {
        if (! in_array($type, [InventoryMovementType::Cancellation, InventoryMovementType::Return], true)) {
            throw ValidationException::withMessages(['type' => 'Only cancellation or return inventory compensation is allowed.']);
        }

        $variantIds = DB::transaction(function () use ($order, $type, $variantQuantities): array {
            $lockedOrder = Order::query()->where('tenant_id', $order->tenant_id)->lockForUpdate()->findOrFail($order->id);
            $allocations = OrderInventoryAllocation::query()
                ->where('tenant_id', $lockedOrder->tenant_id)
                ->where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->get();

            $changed = [];
            if ($variantQuantities === null) {
                foreach ($allocations->where('reserved_quantity', '>', 0) as $allocation) {
                    $stock = InventoryItem::query()
                        ->where('tenant_id', $lockedOrder->tenant_id)
                        ->where('warehouse_id', $allocation->warehouse_id)
                        ->where('product_variant_id', $allocation->product_variant_id)
                        ->lockForUpdate()
                        ->sole();
                    if ($stock->reserved_quantity < $allocation->reserved_quantity) {
                        throw ValidationException::withMessages(['quantities' => 'Order reservation exceeds the warehouse reserved quantity.']);
                    }
                    $released = $allocation->reserved_quantity;
                    $stock->update(['reserved_quantity' => $stock->reserved_quantity - $released]);
                    $allocation->update(['reserved_quantity' => 0, 'released_quantity' => $allocation->released_quantity + $released]);
                    $changed[] = (string) $allocation->product_variant_id;
                }
            }

            $requested = $variantQuantities;
            if ($requested === null) {
                $requested = [];
                foreach ($allocations as $allocation) {
                    $remaining = $allocation->remainingQuantity();
                    if ($remaining > 0) {
                        $requested[(string) $allocation->product_variant_id] = $remaining;
                    }
                }
            }

            foreach ($requested as $variantId => $quantity) {
                $allocation = $allocations->first(fn (OrderInventoryAllocation $candidate): bool => (string) $candidate->product_variant_id === (string) $variantId);
                if ($allocation === null || ! is_int($quantity) || $quantity < 1 || $quantity > $allocation->remainingQuantity()) {
                    throw ValidationException::withMessages(['quantities' => 'Compensation quantity exceeds the uncompensated quantity sold by this order.']);
                }
            }

            foreach ($requested as $variantId => $quantity) {
                $allocation = $allocations->first(fn (OrderInventoryAllocation $candidate): bool => (string) $candidate->product_variant_id === (string) $variantId);
                $stock = InventoryItem::query()
                    ->where('tenant_id', $lockedOrder->tenant_id)
                    ->where('warehouse_id', $allocation->warehouse_id)
                    ->where('product_variant_id', $variantId)
                    ->lockForUpdate()
                    ->sole();
                $after = $stock->quantity + $quantity;

                InventoryMovement::query()->create([
                    'tenant_id' => $lockedOrder->tenant_id,
                    'warehouse_id' => $allocation->warehouse_id,
                    'product_variant_id' => $variantId,
                    'type' => $type,
                    'quantity_delta' => $quantity,
                    'quantity_before' => $stock->quantity,
                    'quantity_after' => $after,
                    'reference_type' => $lockedOrder->getMorphClass(),
                    'reference_id' => $lockedOrder->id,
                    'note' => $type === InventoryMovementType::Cancellation ? 'Channel order cancellation' : 'Channel order return',
                ]);

                $column = $type === InventoryMovementType::Cancellation ? 'cancelled_quantity' : 'returned_quantity';
                $allocation->update([$column => $allocation->{$column} + $quantity]);
                $stock->update(['quantity' => $after]);
                $changed[] = (string) $variantId;
            }

            return array_values(array_unique($changed));
        }, 3);

        foreach ($variantIds as $variantId) {
            InventoryChanged::dispatch($order->tenant_id, $variantId, $order->channel_account_id);
        }

        return $variantIds !== [];
    }
}
