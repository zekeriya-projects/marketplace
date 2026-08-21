<?php

declare(strict_types=1);

namespace App\Integrations\Contracts\Results;

use App\Domain\Sync\Enums\SyncErrorCategory;

final readonly class SyncResult
{
    /** @param array<string, mixed> $context */
    private function __construct(
        public bool $successful,
        public ?SyncErrorCategory $errorCategory = null,
        public ?string $errorCode = null,
        public ?string $safeMessage = null,
        public array $context = [],
        public bool $retryable = false,
    ) {}

    /** @param array<string, mixed> $context */
    public static function success(array $context = []): self
    {
        return new self(true, context: $context);
    }

    /** @param array<string, mixed> $context */
    public static function failure(SyncErrorCategory $category, string $safeMessage, ?string $code = null, array $context = [], bool $retryable = false): self
    {
        return new self(false, $category, $code, $safeMessage, $context, $retryable);
    }
}
