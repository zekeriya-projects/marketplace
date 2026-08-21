<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Channels\Enums\ChannelListingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'channel_account_id', 'product_id', 'product_variant_id', 'external_product_id', 'external_variant_id', 'external_sku', 'external_barcode', 'status', 'channel_price_amount', 'currency', 'published_at', 'last_synced_at', 'metadata'])]
final class ChannelListing extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['status' => ChannelListingStatus::class, 'channel_price_amount' => 'integer', 'published_at' => 'datetime', 'last_synced_at' => 'datetime', 'metadata' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class, 'channel_account_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
