<?php

declare(strict_types=1);

namespace App\Integrations\Trendyol\DTO;

use App\Integrations\Contracts\Results\SyncResult;

final readonly class TrendyolOrderPage
{
    /** @param list<array<string, mixed>> $orders */
    public function __construct(public SyncResult $result, public array $orders = [], public ?string $nextCursor = null) {}
}
