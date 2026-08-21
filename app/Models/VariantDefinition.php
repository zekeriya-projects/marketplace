<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'name', 'values', 'status'])]
final class VariantDefinition extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['values' => 'array', 'status' => CatalogStatus::class];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
