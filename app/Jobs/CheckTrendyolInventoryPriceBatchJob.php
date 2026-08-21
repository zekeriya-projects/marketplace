<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Integrations\Trendyol\DTO\TrendyolCredentials;
use App\Integrations\Trendyol\TrendyolClient;
use App\Jobs\Concerns\TracksSyncOperation;
use App\Models\SyncOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CheckTrendyolInventoryPriceBatchJob implements ShouldQueue
{
    use Queueable, TracksSyncOperation;

    public function __construct(public readonly string $syncOperationId)
    {
        $this->onQueue('inventory-sync');
    }

    public function handle(TrendyolClient $client): void
    {
        $operation = SyncOperation::query()->with(['account', 'entity'])->findOrFail($this->syncOperationId);
        $batchId = (string) data_get($operation->context, 'batch_request_id');
        $response = $client->batchResult(TrendyolCredentials::fromAccount($operation->account), $batchId);
        if (! $response['result']->successful) {
            throw new \RuntimeException($response['result']->safeMessage ?? 'Trendyol işlem sonucu okunamadı.');
        }
        if (($response['payload']['status'] ?? null) !== 'COMPLETED') {
            $this->release(30);

            return;
        }
        $items = is_array($response['payload']['items'] ?? null) ? $response['payload']['items'] : [];
        $failed = (int) ($response['payload']['failedItemCount'] ?? 0) > 0 || collect($items)->contains(fn ($item): bool => strtoupper((string) ($item['status'] ?? '')) === 'FAILURE');
        if ($failed) {
            $operation->update(['status' => SyncOperationStatus::Failed, 'finished_at' => now(), 'error_category' => SyncErrorCategory::Validation, 'error_code' => 'inventory_price_rejected', 'safe_error_message' => 'Trendyol stok veya fiyat güncellemesini reddetti. Ürün eşlemesini ve değerleri kontrol edin.']);

            return;
        }
        $listing = $operation->entity;
        $updates = ['last_synced_at' => now()];
        if ($operation->operation === 'price_push') {
            $updates += ['channel_price_amount' => $listing->variant->base_price_amount, 'currency' => $listing->variant->currency];
        }
        $listing->update($updates);
        $operation->update(['status' => SyncOperationStatus::Succeeded, 'finished_at' => now(), 'error_category' => null, 'error_code' => null, 'safe_error_message' => null]);
    }
}
