<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'order_id', 'warehouse_id', 'product_variant_id', 'ordered_quantity', 'reserved_quantity', 'sold_quantity', 'cancelled_quantity', 'returned_quantity', 'released_quantity'])]
final class OrderInventoryAllocation extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'sold_quantity' => 'integer',
            'cancelled_quantity' => 'integer',
            'returned_quantity' => 'integer',
            'released_quantity' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function remainingQuantity(): int
    {
        return $this->sold_quantity - $this->cancelled_quantity - $this->returned_quantity;
    }
}
