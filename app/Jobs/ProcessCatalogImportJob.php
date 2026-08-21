<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Catalog\Imports\CatalogImportProcessor;
use App\Models\CatalogImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessCatalogImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly string $importId)
    {
        $this->onQueue('imports');
    }

    public function handle(CatalogImportProcessor $processor): void
    {
        $import = CatalogImport::query()->findOrFail($this->importId);
        if ($import->status === 'succeeded') {
            return;
        }
        $import->update(['status' => 'running', 'started_at' => now(), 'safe_error_message' => null]);
        $result = $processor->process($import);
        $import->update(['status' => 'succeeded', 'total_rows' => $result['total'], 'processed_rows' => $result['total'], 'created_rows' => $result['created'], 'updated_rows' => $result['updated'], 'failed_rows' => $result['failed'], 'finished_at' => now()]);
    }

    public function failed(?\Throwable $exception): void
    {
        CatalogImport::query()->whereKey($this->importId)->update(['status' => 'failed', 'safe_error_message' => 'Dosya işlenemedi. Dosya biçimini ve sütunları kontrol edin.', 'finished_at' => now()]);
    }
}
