<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'category_id', 'name', 'trendyol_category_id', 'trendyol_brand_id', 'image_url', 'vat_rate', 'dimensional_weight', 'origin', 'attributes', 'required_attribute_ids'])]
final class TrendyolListingTemplate extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['trendyol_category_id' => 'integer', 'trendyol_brand_id' => 'integer', 'vat_rate' => 'integer', 'dimensional_weight' => 'decimal:2', 'attributes' => 'array', 'required_attribute_ids' => 'array'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
