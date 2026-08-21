<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Exceptions\RetryableSyncException;
use App\Integrations\WooCommerce\WooCommerceOrderImporter;
use App\Models\ChannelSyncState;
use App\Models\SyncOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PullWooCommerceOrdersPageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 180;

    public function __construct(public readonly string $syncOperationId, public readonly int $page = 1)
    {
        $this->onQueue('imports');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180, 600];
    }

    public function handle(WooCommerceOrderImporter $importer): void
    {
        $operation = SyncOperation::query()->with('account')->findOrFail($this->syncOperationId);
        if ($operation->status === SyncOperationStatus::Pending) {
            $operation->update(['status' => SyncOperationStatus::Running, 'started_at' => now(), 'attempt' => 1]);
        }
        $context = $operation->context ?? [];
        $result = $importer->importPage($operation->account, $this->page, (string) $context['from'], (string) $context['to']);
        if (! $result->successful && $result->retryable) {
            throw new RetryableSyncException($result->errorCategory ?? SyncErrorCategory::Unknown, $result->safeMessage ?? 'WooCommerce order pull temporarily failed.', $result->errorCode);
        }
        $itemFailures = (int) ($result->context['failed'] ?? 0);
        $this->recordPage(
            $result->context,
            $result->successful && $itemFailures > 0 ? SyncErrorCategory::Validation : $result->errorCategory,
            $result->successful && $itemFailures > 0 ? 'order_import_partial' : $result->errorCode,
            $result->successful && $itemFailures > 0 ? 'One or more WooCommerce orders could not be fully imported.' : $result->safeMessage,
        );
        $totalPages = (int) ($result->context['total_pages'] ?? 1);
        if ($result->successful && $this->page === 1 && $totalPages > 1) {
            foreach (range(2, $totalPages) as $page) {
                self::dispatch($this->syncOperationId, $page);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->recordPage([], $exception instanceof RetryableSyncException ? $exception->category : SyncErrorCategory::Unknown, $exception instanceof RetryableSyncException ? $exception->safeCode : null, 'A WooCommerce order page could not be imported.');
    }

    /** @param array<string, mixed> $pageContext */
    private function recordPage(array $pageContext, ?SyncErrorCategory $category, ?string $code, ?string $message): void
    {
        DB::transaction(function () use ($pageContext, $category, $code, $message): void {
            $operation = SyncOperation::query()->lockForUpdate()->find($this->syncOperationId);
            if ($operation === null) {
                return;
            }
            $context = $operation->context ?? [];
            $context['total_pages'] = max((int) ($context['total_pages'] ?? 1), (int) ($pageContext['total_pages'] ?? 1));
            $context['processed_pages'] = array_values(array_unique([...($context['processed_pages'] ?? []), $this->page]));
            foreach (['imported', 'failed', 'unmapped'] as $counter) {
                $context[$counter] = (int) ($context[$counter] ?? 0) + (int) ($pageContext[$counter] ?? 0);
            }
            if ($category !== null) {
                $context['failed_pages'] = array_values(array_unique([...($context['failed_pages'] ?? []), $this->page]));
            }
            $complete = count($context['processed_pages']) >= $context['total_pages'];
            $hasFailures = (int) ($context['failed'] ?? 0) > 0 || ($context['failed_pages'] ?? []) !== [];
            $operation->update(['context' => $context, 'status' => $complete ? ($hasFailures ? SyncOperationStatus::Failed : SyncOperationStatus::Succeeded) : SyncOperationStatus::Running, 'finished_at' => $complete ? now() : null, 'error_category' => $category ?? $operation->error_category, 'error_code' => $code ?? $operation->error_code, 'safe_error_message' => $message ?? $operation->safe_error_message]);
            if ($complete && ! $hasFailures) {
                ChannelSyncState::query()->updateOrCreate(['channel_account_id' => $operation->channel_account_id, 'resource_type' => 'orders'], ['tenant_id' => $operation->tenant_id, 'last_synced_from' => $context['from'], 'last_synced_to' => $context['to'], 'metadata' => ['last_operation_id' => $operation->id]]);
            }
        }, 3);
    }
}
