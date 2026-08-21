<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Contracts\DTO\ChannelRequest;
use App\Integrations\Contracts\Results\SyncResult;
use App\Models\SyncOperation;

final class PublishWooCommerceProductJob extends ChannelSyncJob
{
    protected function execute(SyncOperation $operation): SyncResult
    {
        return app(ConnectorRegistry::class)->for($operation->account->channel->code)->pushProduct(
            new ChannelRequest($operation->tenant_id, $operation->channel_account_id, $operation->entity_id),
        );
    }
}
