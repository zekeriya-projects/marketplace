<?php

declare(strict_types=1);

namespace App\Domain\Sync\Actions;

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Jobs\SyncVariantInventoryJob;
use App\Jobs\SyncVariantPriceJob;
use App\Models\ChannelListing;
use Illuminate\Database\UniqueConstraintViolationException;

final class DispatchVariantSync
{
    public function execute(string $tenantId, string $variantId, string $operation, ?string $excludedChannelAccountId = null): int
    {
        $count = 0;
        ChannelListing::query()->where('tenant_id', $tenantId)->where('product_variant_id', $variantId)
            ->where('status', ChannelListingStatus::Active)->whereHas('account', fn ($query) => $query->where('status', ChannelAccountStatus::Active))
            ->when($excludedChannelAccountId !== null, fn ($query) => $query->where('channel_account_id', '!=', $excludedChannelAccountId))
            ->with('account')->each(function (ChannelListing $listing) use ($operation, &$count): void {
                $existing = $listing->account->syncOperations()->where('operation', $operation)->where('entity_type', $listing->getMorphClass())->where('entity_id', $listing->id)->whereIn('status', ['pending', 'running'])->exists();
                if ($existing) {
                    return;
                }
                try {
                    $sync = $listing->account->syncOperations()->create(['tenant_id' => $listing->tenant_id, 'operation' => $operation, 'entity_type' => $listing->getMorphClass(), 'entity_id' => $listing->id]);
                } catch (UniqueConstraintViolationException) {
                    return;
                }
                ($operation === 'inventory_push' ? SyncVariantInventoryJob::class : SyncVariantPriceJob::class)::dispatch($sync->id);
                $count++;
            });

        return $count;
    }
}
