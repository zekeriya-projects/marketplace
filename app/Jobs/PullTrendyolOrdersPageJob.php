<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Exceptions\RetryableSyncException;
use App\Integrations\Contracts\Results\SyncResult;
use App\Integrations\Trendyol\TrendyolOrderImporter;
use App\Models\ChannelSyncState;
use App\Models\SyncOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PullTrendyolOrdersPageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 180;

    public function __construct(public readonly string $syncOperationId, public readonly ?string $cursor = null)
    {
        $this->onQueue('orders');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180, 600];
    }

    public function middleware(): array
    {
        return [(new RateLimitedWithRedis('trendyol-sync'))->releaseAfter(10)];
    }

    public function handle(TrendyolOrderImporter $importer): void
    {
        $operation = SyncOperation::query()->with('account')->findOrFail($this->syncOperationId);
        if ($operation->status === SyncOperationStatus::Pending) {
            $operation->update(['status' => SyncOperationStatus::Running, 'started_at' => now(), 'attempt' => 1]);
        }
        $context = $operation->context ?? [];
        $from = Carbon::parse((string) $context['from'])->getTimestampMs();
        $to = Carbon::parse((string) $context['to'])->getTimestampMs();
        $result = $importer->importPage($operation->account, $from, $to, $this->cursor);
        if (! $result->successful && $result->retryable) {
            throw new RetryableSyncException($result->errorCategory ?? SyncErrorCategory::Unknown, $result->safeMessage ?? 'Trendyol sipariş aktarımı geçici olarak başarısız oldu.', $result->errorCode);
        }
        $this->recordPage($result);
        $nextCursor = $result->context['next_cursor'] ?? null;
        if ($result->successful && is_string($nextCursor) && $nextCursor !== '') {
            self::dispatch($this->syncOperationId, $nextCursor);
        }
    }

    public function failed(Throwable $exception): void
    {
        $operation = SyncOperation::query()->find($this->syncOperationId);
        $operation?->update(['status' => SyncOperationStatus::Failed, 'finished_at' => now(), 'error_category' => $exception instanceof RetryableSyncException ? $exception->category : SyncErrorCategory::Unknown, 'error_code' => $exception instanceof RetryableSyncException ? $exception->safeCode : null, 'safe_error_message' => 'Trendyol sipariş sayfası aktarılamadı.']);
    }

    private function recordPage(SyncResult $result): void
    {
        DB::transaction(function () use ($result): void {
            $operation = SyncOperation::query()->lockForUpdate()->findOrFail($this->syncOperationId);
            $context = $operation->context ?? [];
            foreach (['imported', 'failed', 'unmapped'] as $counter) {
                $context[$counter] = (int) ($context[$counter] ?? 0) + (int) ($result->context[$counter] ?? 0);
            }
            $context['processed_pages'] = (int) ($context['processed_pages'] ?? 0) + 1;
            $context['cursor'] = $result->context['next_cursor'] ?? null;
            $complete = $result->successful && $context['cursor'] === null;
            $hasFailures = ! $result->successful || (int) $context['failed'] > 0;
            $operation->update(['context' => $context, 'status' => $complete ? ($hasFailures ? SyncOperationStatus::Failed : SyncOperationStatus::Succeeded) : ($hasFailures ? SyncOperationStatus::Failed : SyncOperationStatus::Running), 'finished_at' => $complete || $hasFailures ? now() : null, 'error_category' => $hasFailures ? ($result->errorCategory ?? SyncErrorCategory::Validation) : null, 'error_code' => $hasFailures ? ($result->errorCode ?? 'order_import_partial') : null, 'safe_error_message' => $hasFailures ? ($result->safeMessage ?? 'Bir veya daha fazla Trendyol siparişi tam olarak aktarılamadı.') : null]);
            if ($complete && ! $hasFailures) {
                ChannelSyncState::query()->updateOrCreate(['channel_account_id' => $operation->channel_account_id, 'resource_type' => 'orders'], ['tenant_id' => $operation->tenant_id, 'last_synced_from' => $context['from'], 'last_synced_to' => $context['to'], 'cursor' => null, 'metadata' => ['last_operation_id' => $operation->id]]);
            }
        }, 3);
    }
}
