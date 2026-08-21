<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\SyncOperation;
use Illuminate\Support\Facades\Log;

final class SyncOperationObserver
{
    public function updated(SyncOperation $operation): void
    {
        if (! $operation->wasChanged('status')) {
            return;
        }

        Log::info('sync_operation_status_changed', [
            'sync_operation_id' => $operation->id,
            'tenant_id' => $operation->tenant_id,
            'channel_account_id' => $operation->channel_account_id,
            'operation' => $operation->operation,
            'entity_type' => $operation->entity_type,
            'entity_id' => $operation->entity_id,
            'status' => $operation->status->value,
            'attempt' => $operation->attempt,
            'error_category' => $operation->error_category?->value,
            'error_code' => $operation->error_code,
        ]);
    }
}
