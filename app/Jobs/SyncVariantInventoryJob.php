<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Contracts\DTO\ChannelRequest;
use App\Integrations\Contracts\Results\SyncResult;
use App\Models\SyncOperation;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;

final class SyncVariantInventoryJob extends ChannelSyncJob
{
    public function __construct(string $syncOperationId)
    {
        parent::__construct($syncOperationId);
        $this->onQueue('inventory-sync');
    }

    protected function execute(SyncOperation $operation): SyncResult
    {
        return app(ConnectorRegistry::class)->for($operation->account->channel->code)->updateInventory(new ChannelRequest($operation->tenant_id, $operation->channel_account_id, $operation->entity_id));
    }

    public function middleware(): array
    {
        return $this->usesTrendyol() ? [(new RateLimitedWithRedis('trendyol-sync'))->releaseAfter(10)] : [];
    }

    protected function completeSuccessfulOperation(SyncOperation $operation, SyncResult $result): bool
    {
        if (($result->context['awaiting_batch'] ?? false) !== true) {
            return true;
        }
        $operation->update(['context' => $result->context]);
        CheckTrendyolInventoryPriceBatchJob::dispatch($operation->id)->delay(now()->addSeconds(10));

        return false;
    }

    private function usesTrendyol(): bool
    {
        return SyncOperation::query()->whereKey($this->syncOperationId)->whereHas('account.channel', fn ($query) => $query->where('code', 'trendyol'))->exists();
    }
}
