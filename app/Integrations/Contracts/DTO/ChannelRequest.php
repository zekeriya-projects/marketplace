<?php

declare(strict_types=1);

namespace App\Integrations\Contracts\DTO;

final readonly class ChannelRequest
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public string $tenantId,
        public string $channelAccountId,
        public ?string $entityId = null,
        public array $options = [],
    ) {}
}
