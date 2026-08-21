<?php

declare(strict_types=1);

namespace App\Integrations\WooCommerce;

use App\Domain\Catalog\Support\MinorUnits;
use App\Domain\Orders\DTO\NormalizedOrder;
use App\Domain\Orders\DTO\NormalizedOrderItem;
use App\Domain\Orders\Enums\OrderStatus;
use DateTimeImmutable;
use InvalidArgumentException;

final class WooCommerceOrderMapper
{
    /** @param array<string, mixed> $payload */
    public function map(array $payload): NormalizedOrder
    {
        $id = (string) ($payload['id'] ?? '');
        $currency = strtoupper((string) ($payload['currency'] ?? ''));
        $lines = $payload['line_items'] ?? null;
        if ($id === '' || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || ! is_array($lines) || $lines === []) {
            throw new InvalidArgumentException('WooCommerce order identity, currency, and line items are required.');
        }

        $items = array_map(function (array $line): NormalizedOrderItem {
            $quantity = max(1, (int) ($line['quantity'] ?? 0));
            $subtotal = $this->money($line['subtotal'] ?? '0');
            $total = $this->money($line['total'] ?? '0');
            $tax = $this->money($line['total_tax'] ?? '0');
            $variationId = (int) ($line['variation_id'] ?? 0);

            return new NormalizedOrderItem(
                name: trim((string) ($line['name'] ?? '')),
                quantity: $quantity,
                unitPriceAmount: intdiv($subtotal, $quantity),
                discountAmount: max(0, $subtotal - $total),
                taxAmount: $tax,
                totalAmount: $total + $tax,
                externalItemId: isset($line['id']) ? (string) $line['id'] : null,
                externalProductId: isset($line['product_id']) ? (string) $line['product_id'] : null,
                externalVariantId: $variationId > 0 ? (string) $variationId : null,
                externalSku: ($line['sku'] ?? '') !== '' ? (string) $line['sku'] : null,
            );
        }, $lines);
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $shipping = is_array($payload['shipping'] ?? null) ? $payload['shipping'] : [];

        return new NormalizedOrder(
            externalOrderId: $id,
            externalOrderNumber: isset($payload['number']) ? (string) $payload['number'] : null,
            status: $this->status((string) ($payload['status'] ?? 'pending')),
            externalStatus: isset($payload['status']) ? (string) $payload['status'] : null,
            currency: $currency,
            subtotalAmount: array_sum(array_map(fn (NormalizedOrderItem $item): int => $item->unitPriceAmount * $item->quantity, $items)),
            discountAmount: $this->money($payload['discount_total'] ?? '0'),
            shippingAmount: $this->money($payload['shipping_total'] ?? '0'),
            taxAmount: $this->money($payload['total_tax'] ?? '0'),
            totalAmount: $this->money($payload['total'] ?? '0'),
            customer: ['name' => trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')), 'email' => $billing['email'] ?? null, 'phone' => $billing['phone'] ?? null],
            shippingAddress: $shipping,
            billingAddress: $billing,
            orderedAt: new DateTimeImmutable((string) ($payload['date_created_gmt'] ?? 'now'), new \DateTimeZone('UTC')),
            items: $items,
        );
    }

    private function money(mixed $value): int
    {
        return MinorUnits::fromDecimal((string) $value);
    }

    private function status(string $status): OrderStatus
    {
        return match ($status) {
            'processing' => OrderStatus::Processing,
            'completed' => OrderStatus::Delivered,
            'cancelled', 'failed', 'trash' => OrderStatus::Cancelled,
            'refunded' => OrderStatus::Returned,
            'on-hold' => OrderStatus::Confirmed,
            default => OrderStatus::Pending,
        };
    }
}
