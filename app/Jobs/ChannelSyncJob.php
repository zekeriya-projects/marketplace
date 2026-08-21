<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Domain\Sync\Exceptions\RetryableSyncException;
use App\Integrations\Contracts\Results\SyncResult;
use App\Jobs\Concerns\TracksSyncOperation;
use App\Models\SyncOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

abstract class ChannelSyncJob implements ShouldQueue
{
    use Queueable, TracksSyncOperation;

    public function __construct(public readonly string $syncOperationId)
    {
        $this->onQueue('default');
    }

    final public function handle(): void
    {
        $operation = SyncOperation::query()->with('account.channel')->findOrFail($this->syncOperationId);
        $this->markRunning($operation);
        $result = $this->execute($operation);

        if ($result->successful) {
            if (! $this->completeSuccessfulOperation($operation, $result)) {
                return;
            }
            $this->markSucceeded($operation, $result->context);

            return;
        }

        if ($result->retryable) {
            throw new RetryableSyncException($result->errorCategory ?? SyncErrorCategory::Unknown, $result->safeMessage ?? 'The remote service is temporarily unavailable.', $result->errorCode);
        }

        $this->markFailed(
            $operation,
            $result->errorCategory ?? SyncErrorCategory::Unknown,
            $result->safeMessage ?? 'The synchronization could not be completed.',
            $result->errorCode,
        );
    }

    abstract protected function execute(SyncOperation $operation): SyncResult;

    protected function completeSuccessfulOperation(SyncOperation $operation, SyncResult $result): bool
    {
        return true;
    }
}
