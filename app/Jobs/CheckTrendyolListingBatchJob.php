<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Integrations\Trendyol\DTO\TrendyolCredentials;
use App\Integrations\Trendyol\TrendyolClient;
use App\Jobs\Concerns\TracksSyncOperation;
use App\Models\SyncOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CheckTrendyolListingBatchJob implements ShouldQueue
{
    use Queueable, TracksSyncOperation;

    public function __construct(public readonly string $syncOperationId)
    {
        $this->onQueue('imports');
    }

    public function handle(TrendyolClient $client): void
    {
        $operation = SyncOperation::query()->with(['account', 'entity'])->findOrFail($this->syncOperationId);
        $listing = $operation->entity;
        $batch = (string) data_get($listing->metadata, 'batch_request_id');
        $response = $client->batchResult(TrendyolCredentials::fromAccount($operation->account), $batch);
        if (! $response['result']->successful) {
            throw new \RuntimeException($response['result']->safeMessage ?? 'Trendyol sonucu okunamadı.');
        }
        if (($response['payload']['status'] ?? null) !== 'COMPLETED') {
            $this->release(30);

            return;
        }
        $failed = (int) ($response['payload']['failedItemCount'] ?? 0) > 0;
        $reason = $failed ? 'Trendyol ürün yayınını reddetti. Kategori ve özellik eşlemesini kontrol edin.' : null;
        $listing->update(['status' => $failed ? ChannelListingStatus::Rejected : ChannelListingStatus::Active, 'published_at' => $failed ? null : now(), 'last_synced_at' => now()]);
        $operation->update(['status' => $failed ? SyncOperationStatus::Failed : SyncOperationStatus::Succeeded, 'finished_at' => now(), 'error_category' => $failed ? SyncErrorCategory::Validation : null, 'error_code' => $failed ? 'listing_rejected' : null, 'safe_error_message' => $reason]);
    }
}
