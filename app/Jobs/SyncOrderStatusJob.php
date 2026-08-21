<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Orders\Actions\ApplyOrderInventory;
use App\Domain\Orders\Enums\OrderStatus;
use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Contracts\DTO\ChannelRequest;
use App\Integrations\Contracts\Results\SyncResult;
use App\Models\Order;
use App\Models\SyncOperation;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;
use Illuminate\Support\Facades\DB;

final class SyncOrderStatusJob extends ChannelSyncJob
{
    public function __construct(string $syncOperationId)
    {
        parent::__construct($syncOperationId);
        $this->onQueue('orders');
    }

    protected function execute(SyncOperation $operation): SyncResult
    {
        return app(ConnectorRegistry::class)->for($operation->account->channel->code)->updateOrderStatus(new ChannelRequest($operation->tenant_id, $operation->channel_account_id, $operation->entity_id, ['status' => $operation->context['target_status'] ?? null]));
    }

    protected function completeSuccessfulOperation(SyncOperation $operation, SyncResult $result): bool
    {
        $order = DB::transaction(function () use ($operation, $result): Order {
            $order = Order::query()->where('tenant_id', $operation->tenant_id)->where('channel_account_id', $operation->channel_account_id)->whereKey($operation->entity_id)->lockForUpdate()->firstOrFail();
            $order->update([
                'status' => OrderStatus::from((string) $operation->context['target_status']),
                'external_status' => (string) ($result->context['external_status'] ?? $operation->context['target_status']),
            ]);

            return $order;
        });
        app(ApplyOrderInventory::class)->execute($order);

        return true;
    }

    public function middleware(): array
    {
        return $this->usesTrendyol() ? [(new RateLimitedWithRedis('trendyol-sync'))->releaseAfter(10)] : [];
    }

    private function usesTrendyol(): bool
    {
        return SyncOperation::query()->whereKey($this->syncOperationId)->whereHas('account.channel', fn ($query) => $query->where('code', 'trendyol'))->exists();
    }
}
