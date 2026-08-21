<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Sync\Actions\CreateSyncOperation;
use App\Jobs\PullTrendyolOrdersPageJob;
use App\Models\ChannelAccount;
use App\Models\ChannelSyncState;
use App\Models\SyncOperation;
use Illuminate\Support\Carbon;

final class DispatchTrendyolOrderPull
{
    public function __construct(private readonly CreateSyncOperation $createSyncOperation) {}

    public function execute(ChannelAccount $account): SyncOperation
    {
        $state = ChannelSyncState::query()->firstOrCreate(['channel_account_id' => $account->id, 'resource_type' => 'orders'], ['tenant_id' => $account->tenant_id]);
        $to = now('UTC')->startOfSecond();
        $from = ($state->last_synced_to?->subMinutes(5) ?? Carbon::instance($to)->subDays(14))->utc();
        $operation = $this->createSyncOperation->execute($account, 'order_pull', context: [
            'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'cursor' => null, 'processed_pages' => 0,
            'imported' => 0, 'failed' => 0, 'unmapped' => 0,
        ]);
        PullTrendyolOrdersPageJob::dispatch($operation->id);

        return $operation;
    }
}
