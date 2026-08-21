<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Domain\Sync\Enums\SyncOperationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['tenant_id', 'channel_account_id', 'operation', 'entity_type', 'entity_id', 'status', 'attempt', 'started_at', 'finished_at', 'error_category', 'error_code', 'safe_error_message', 'context'])]
final class SyncOperation extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['status' => SyncOperationStatus::class, 'error_category' => SyncErrorCategory::class, 'attempt' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'context' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class, 'channel_account_id');
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }
}
