<?php

declare(strict_types=1);

namespace App\Domain\Sync\Enums;

enum SyncOperationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
