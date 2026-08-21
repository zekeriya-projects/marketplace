<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Exceptions\RetryableSyncException;
use App\Models\SyncOperation;
use Throwable;

trait TracksSyncOperation
{
    public int $tries = 5;

    public int $timeout = 120;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    protected function markRunning(SyncOperation $operation): void
    {
        $operation->update(['status' => SyncOperationStatus::Running, 'attempt' => $operation->attempt + 1, 'started_at' => now(), 'finished_at' => null, 'error_category' => null, 'error_code' => null, 'safe_error_message' => null]);
    }

    protected function markSucceeded(SyncOperation $operation, array $context = []): void
    {
        $operation->update(['status' => SyncOperationStatus::Succeeded, 'finished_at' => now(), 'context' => $context]);
    }

    protected function markFailed(SyncOperation $operation, SyncErrorCategory $category, string $safeMessage, ?string $code = null): void
    {
        $operation->update(['status' => SyncOperationStatus::Failed, 'finished_at' => now(), 'error_category' => $category, 'error_code' => $code, 'safe_error_message' => $safeMessage]);
    }

    public function failed(Throwable $exception): void
    {
        $operation = SyncOperation::query()->find($this->syncOperationId);
        if ($operation !== null) {
            $this->markFailed(
                $operation,
                $exception instanceof RetryableSyncException ? $exception->category : SyncErrorCategory::Unknown,
                $exception instanceof RetryableSyncException ? $exception->safeMessage : 'The synchronization could not be completed.',
                $exception instanceof RetryableSyncException ? $exception->safeCode : null,
            );
        }
    }
}
