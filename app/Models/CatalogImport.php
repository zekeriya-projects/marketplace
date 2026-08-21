<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'user_id', 'format', 'original_name', 'disk', 'path', 'status', 'total_rows', 'processed_rows', 'created_rows', 'updated_rows', 'failed_rows', 'safe_error_message', 'started_at', 'finished_at'])]
final class CatalogImport extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['total_rows' => 'integer', 'processed_rows' => 'integer', 'created_rows' => 'integer', 'updated_rows' => 'integer', 'failed_rows' => 'integer', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
