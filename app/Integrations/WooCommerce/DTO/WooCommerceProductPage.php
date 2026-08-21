<?php

declare(strict_types=1);

namespace App\Integrations\WooCommerce\DTO;

use App\Integrations\Contracts\Results\SyncResult;

final readonly class WooCommerceProductPage
{
    /** @param list<array<string, mixed>> $products */
    public function __construct(
        public SyncResult $result,
        public array $products = [],
        public int $totalPages = 1,
    ) {}
}
