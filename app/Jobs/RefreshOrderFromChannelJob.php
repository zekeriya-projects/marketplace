<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Contracts\DTO\ChannelRequest;
use App\Integrations\Contracts\Results\SyncResult;
use App\Models\SyncOperation;

final class RefreshOrderFromChannelJob extends ChannelSyncJob
{
    public function __construct(string $syncOperationId)
    {
        parent::__construct($syncOperationId);
        $this->onQueue('orders');
    }

    protected function execute(SyncOperation $operation): SyncResult
    {
        return app(ConnectorRegistry::class)->for($operation->account->channel->code)->pullOrder(new ChannelRequest($operation->tenant_id, $operation->channel_account_id, $operation->entity_id));
    }
}
