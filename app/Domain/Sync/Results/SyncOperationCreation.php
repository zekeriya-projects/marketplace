<?php

declare(strict_types=1);

namespace App\Domain\Sync\Results;

use App\Models\SyncOperation;

final readonly class SyncOperationCreation
{
    public function __construct(public SyncOperation $operation, public bool $created) {}
}
