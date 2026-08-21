<?php

declare(strict_types=1);

namespace App\Domain\Sync\Exceptions;

use App\Domain\Sync\Enums\SyncErrorCategory;
use RuntimeException;

final class RetryableSyncException extends RuntimeException
{
    public function __construct(
        public readonly SyncErrorCategory $category,
        public readonly string $safeMessage,
        public readonly ?string $safeCode = null,
    ) {
        parent::__construct($safeMessage);
    }
}
