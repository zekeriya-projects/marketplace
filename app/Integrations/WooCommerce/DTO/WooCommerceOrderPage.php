<?php

declare(strict_types=1);

namespace App\Integrations\WooCommerce\DTO;

use App\Integrations\Contracts\Results\SyncResult;

final readonly class WooCommerceOrderPage
{
    /** @param list<array<string, mixed>> $orders */
    public function __construct(public SyncResult $result, public array $orders = [], public int $totalPages = 1) {}
}
