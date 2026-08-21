<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Contracts\DTO\ChannelRequest;
use App\Integrations\Contracts\Results\SyncResult;
use App\Models\SyncOperation;
use Throwable;

final class TestChannelConnectionJob extends ChannelSyncJob
{
    protected function execute(SyncOperation $operation): SyncResult
    {
        $result = app(ConnectorRegistry::class)->for($operation->account->channel->code)->testConnection(new ChannelRequest(
            tenantId: $operation->tenant_id,
            channelAccountId: $operation->channel_account_id,
        ));

        if ($result->successful) {
            $operation->account->update(['status' => ChannelAccountStatus::Active, 'last_connected_at' => now()]);
        } elseif (! $result->retryable) {
            $operation->account->update(['status' => ChannelAccountStatus::Error]);
        }

        return $result;
    }

    public function failed(Throwable $exception): void
    {
        parent::failed($exception);
        SyncOperation::query()->find($this->syncOperationId)?->account()->update(['status' => ChannelAccountStatus::Error]);
    }
}
