<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Channels\Enums\ChannelAccountStatus;
use Database\Factories\ChannelAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'channel_id', 'name', 'credentials_encrypted', 'settings', 'status', 'last_connected_at'])]
#[Hidden(['credentials_encrypted'])]
final class ChannelAccount extends Model
{
    /** @use HasFactory<ChannelAccountFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'credentials_encrypted' => 'encrypted:array',
            'settings' => 'array',
            'status' => ChannelAccountStatus::class,
            'last_connected_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(ChannelListing::class);
    }

    public function syncOperations(): HasMany
    {
        return $this->hasMany(SyncOperation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function syncStates(): HasMany
    {
        return $this->hasMany(ChannelSyncState::class);
    }
}
