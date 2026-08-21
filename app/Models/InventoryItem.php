<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'warehouse_id', 'product_variant_id', 'quantity', 'reserved_quantity'])]
final class InventoryItem extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'reserved_quantity' => 'integer'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function availableQuantity(): int
    {
        return $this->quantity - $this->reserved_quantity;
    }
}
