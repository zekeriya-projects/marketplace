<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Orders\Enums\OrderItemMappingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'order_id', 'product_variant_id', 'channel_listing_id', 'external_item_id', 'external_product_id', 'external_variant_id', 'external_sku', 'external_barcode', 'name', 'quantity', 'unit_price_amount', 'discount_amount', 'tax_amount', 'total_amount', 'mapping_status'])]
final class OrderItem extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['mapping_status' => OrderItemMappingStatus::class, 'quantity' => 'integer', 'unit_price_amount' => 'integer', 'discount_amount' => 'integer', 'tax_amount' => 'integer', 'total_amount' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(ChannelListing::class, 'channel_listing_id');
    }
}
