<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\Enums\CatalogStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'category_id', 'brand_id', 'name', 'brand', 'description', 'status', 'short_name', 'invoice_name', 'custom_code_1', 'custom_code_2', 'compare_at_price_amount', 'purchase_price_amount', 'desi', 'desi_2', 'vat_rate', 'excise_tax_rate', 'communication_tax_rate', 'disable_external_sync', 'vat_exemption_code', 'expiration_date'])]
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['status' => CatalogStatus::class, 'compare_at_price_amount' => 'integer', 'purchase_price_amount' => 'integer', 'desi' => 'decimal:2', 'desi_2' => 'decimal:2', 'vat_rate' => 'integer', 'excise_tax_rate' => 'decimal:2', 'communication_tax_rate' => 'decimal:2', 'disable_external_sync' => 'boolean', 'expiration_date' => 'date:Y-m-d'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brandReference(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function channelListings(): HasMany
    {
        return $this->hasMany(ChannelListing::class);
    }
}
