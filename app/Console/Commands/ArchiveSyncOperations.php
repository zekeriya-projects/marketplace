<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sync\Enums\SyncOperationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ArchiveSyncOperations extends Command
{
    protected $signature = 'sync:archive {--chunk= : Number of rows processed per transaction}';

    protected $description = 'Archive expired terminal synchronization operations and remove their technical rows';

    public function handle(): int
    {
        $chunkSize = (int) ($this->option('chunk') ?: config('sync.cleanup_chunk_size', 1000));
        if ($chunkSize < 1 || $chunkSize > 10000) {
            $this->error('Chunk size must be between 1 and 10000.');

            return self::INVALID;
        }

        $archived = 0;
        foreach ([SyncOperationStatus::Succeeded, SyncOperationStatus::Skipped, SyncOperationStatus::Failed] as $status) {
            $days = (int) config("sync.operation_retention_days.{$status->value}");
            if ($days < 1) {
                throw new \RuntimeException("Invalid retention period for {$status->value} operations.");
            }
            $cutoff = now()->subDays($days);

            do {
                $processed = $this->archiveChunk($status, $cutoff, $chunkSize);
                $archived += $processed;
            } while ($processed === $chunkSize);
        }

        $this->info("Archived {$archived} synchronization operations.");
        $sizes = DB::selectOne("SELECT pg_total_relation_size('sync_operations') AS live_bytes, pg_total_relation_size('sync_operation_archives') AS archive_bytes");
        Log::info('Synchronization-operation archival completed.', [
            'archived_count' => $archived,
            'live_table_bytes' => (int) $sizes->live_bytes,
            'archive_table_bytes' => (int) $sizes->archive_bytes,
        ]);

        return self::SUCCESS;
    }

    private function archiveChunk(SyncOperationStatus $status, Carbon $cutoff, int $chunkSize): int
    {
        return DB::transaction(function () use ($status, $cutoff, $chunkSize): int {
            $rows = DB::table('sync_operations')
                ->where('status', $status->value)
                ->where(fn ($query) => $query->where('finished_at', '<', $cutoff)->orWhere(fn ($fallback) => $fallback->whereNull('finished_at')->where('created_at', '<', $cutoff)))
                ->orderBy('id')->limit($chunkSize)->lockForUpdate()->get();

            foreach ($rows->groupBy(fn ($row): string => implode('|', [$row->tenant_id, $row->channel_account_id, $row->operation, Carbon::parse($row->created_at)->startOfMinute()->toDateTimeString(), $row->status])) as $group) {
                $latest = $group->sortByDesc('created_at')->first();
                DB::statement(
                    <<<'SQL'
                    INSERT INTO sync_operation_archives
                        (tenant_id, channel_account_id, operation, bucket_at, status, operation_count, latest_at, latest_error, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ON CONFLICT (tenant_id, channel_account_id, operation, bucket_at, status)
                    DO UPDATE SET
                        operation_count = sync_operation_archives.operation_count + EXCLUDED.operation_count,
                        latest_at = GREATEST(sync_operation_archives.latest_at, EXCLUDED.latest_at),
                        latest_error = CASE WHEN EXCLUDED.latest_at >= sync_operation_archives.latest_at THEN EXCLUDED.latest_error ELSE sync_operation_archives.latest_error END,
                        updated_at = NOW()
                    SQL,
                    [$latest->tenant_id, $latest->channel_account_id, $latest->operation, Carbon::parse($latest->created_at)->startOfMinute(), $latest->status, $group->count(), $latest->created_at, $latest->safe_error_message],
                );
            }

            if ($rows->isNotEmpty()) {
                DB::table('sync_operations')->whereIn('id', $rows->pluck('id'))->delete();
            }

            return $rows->count();
        }, 3);
    }
}
