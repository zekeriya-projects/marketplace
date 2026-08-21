<?php

declare(strict_types=1);

namespace App\Domain\Orders\DTO;

final readonly class NormalizedOrderItem
{
    public function __construct(
        public string $name,
        public int $quantity,
        public int $unitPriceAmount,
        public int $discountAmount,
        public int $taxAmount,
        public int $totalAmount,
        public ?string $externalItemId = null,
        public ?string $externalProductId = null,
        public ?string $externalVariantId = null,
        public ?string $externalSku = null,
        public ?string $externalBarcode = null,
    ) {}
}
