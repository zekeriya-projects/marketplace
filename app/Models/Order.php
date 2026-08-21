<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Orders\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'channel_account_id', 'external_order_id', 'external_order_number', 'status', 'external_status', 'currency', 'subtotal_amount', 'discount_amount', 'shipping_amount', 'tax_amount', 'total_amount', 'customer_snapshot', 'shipping_address_snapshot', 'billing_address_snapshot', 'ordered_at', 'imported_at', 'inventory_applied_at'])]
final class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_amount' => 'integer', 'discount_amount' => 'integer', 'shipping_amount' => 'integer', 'tax_amount' => 'integer', 'total_amount' => 'integer',
            'customer_snapshot' => 'array', 'shipping_address_snapshot' => 'array', 'billing_address_snapshot' => 'array',
            'ordered_at' => 'immutable_datetime', 'imported_at' => 'immutable_datetime', 'inventory_applied_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class, 'channel_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventoryAllocations(): HasMany
    {
        return $this->hasMany(OrderInventoryAllocation::class);
    }
}
