<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'channel_account_id', 'resource_type', 'cursor', 'last_synced_from', 'last_synced_to', 'metadata'])]
final class ChannelSyncState extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['last_synced_from' => 'immutable_datetime', 'last_synced_to' => 'immutable_datetime', 'metadata' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class, 'channel_account_id');
    }
}
