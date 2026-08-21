<?php

declare(strict_types=1);

namespace App\Domain\Orders\DTO;

use App\Domain\Orders\Enums\OrderStatus;
use DateTimeImmutable;

final readonly class NormalizedOrder
{
    /** @param array<string, mixed> $customer @param array<string, mixed> $shippingAddress @param array<string, mixed>|null $billingAddress @param list<NormalizedOrderItem> $items */
    public function __construct(
        public string $externalOrderId,
        public ?string $externalOrderNumber,
        public OrderStatus $status,
        public ?string $externalStatus,
        public string $currency,
        public int $subtotalAmount,
        public int $discountAmount,
        public int $shippingAmount,
        public int $taxAmount,
        public int $totalAmount,
        public array $customer,
        public array $shippingAddress,
        public ?array $billingAddress,
        public DateTimeImmutable $orderedAt,
        public array $items,
    ) {}
}
