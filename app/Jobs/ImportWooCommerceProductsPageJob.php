<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Exceptions\RetryableSyncException;
use App\Integrations\WooCommerce\WooCommerceCatalogImporter;
use App\Models\SyncOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImportWooCommerceProductsPageJob implements ShouldQueue
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

    public function handle(WooCommerceCatalogImporter $importer): void
    {
        $operation = SyncOperation::query()->with('account')->findOrFail($this->syncOperationId);
        if ($operation->status === SyncOperationStatus::Pending) {
            $operation->update(['status' => SyncOperationStatus::Running, 'started_at' => now(), 'attempt' => 1]);
        }

        $result = $importer->importPage($operation->account, $this->page);
        if (! $result->successful) {
            if ($result->retryable) {
                throw new RetryableSyncException($result->errorCategory ?? SyncErrorCategory::Unknown, $result->safeMessage ?? 'WooCommerce catalog import temporarily failed.', $result->errorCode);
            }
            $this->recordPage($result->context, $result->errorCategory, $result->errorCode, $result->safeMessage);

            return;
        }

        $totalPages = (int) ($result->context['total_pages'] ?? 1);
        $pageFailures = (int) ($result->context['failed'] ?? 0);
        $this->recordPage(
            $result->context,
            $pageFailures > 0 ? SyncErrorCategory::Validation : null,
            $pageFailures > 0 ? 'product_import_partial' : null,
            $pageFailures > 0 ? 'One or more WooCommerce products could not be imported.' : null,
        );
        if ($this->page === 1 && $totalPages > 1) {
            foreach (range(2, $totalPages) as $page) {
                self::dispatch($this->syncOperationId, $page);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        $category = $exception instanceof RetryableSyncException ? $exception->category : SyncErrorCategory::Unknown;
        $code = $exception instanceof RetryableSyncException ? $exception->safeCode : null;
        $message = $exception instanceof RetryableSyncException ? $exception->safeMessage : 'A WooCommerce catalog page could not be imported.';
        $this->recordPage(['total_pages' => $this->page], $category, $code, $message);
    }

    /** @param array<string, mixed> $pageContext */
    private function recordPage(array $pageContext, ?SyncErrorCategory $category = null, ?string $code = null, ?string $message = null): void
    {
        DB::transaction(function () use ($pageContext, $category, $code, $message): void {
            $operation = SyncOperation::query()->lockForUpdate()->find($this->syncOperationId);
            if ($operation === null) {
                return;
            }
            $context = $operation->context ?? [];
            $context['total_pages'] = max((int) ($context['total_pages'] ?? 1), (int) ($pageContext['total_pages'] ?? 1));
            $context['processed_pages'] = array_values(array_unique([...($context['processed_pages'] ?? []), $this->page]));
            foreach (['imported', 'failed', 'skipped'] as $counter) {
                $context[$counter] = (int) ($context[$counter] ?? 0) + (int) ($pageContext[$counter] ?? 0);
            }
            if ($category !== null) {
                $context['failed_pages'] = array_values(array_unique([...($context['failed_pages'] ?? []), $this->page]));
            }
            $complete = count($context['processed_pages']) >= $context['total_pages'];
            $hasFailures = (int) ($context['failed'] ?? 0) > 0 || ($context['failed_pages'] ?? []) !== [];
            $operation->update([
                'context' => $context,
                'status' => $complete ? ($hasFailures ? SyncOperationStatus::Failed : SyncOperationStatus::Succeeded) : SyncOperationStatus::Running,
                'finished_at' => $complete ? now() : null,
                'error_category' => $category ?? $operation->error_category,
                'error_code' => $code ?? $operation->error_code,
                'safe_error_message' => $message ?? $operation->safe_error_message,
            ]);
        }, 3);
    }
}
