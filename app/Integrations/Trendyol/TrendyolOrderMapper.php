<?php

declare(strict_types=1);

namespace App\Integrations\Trendyol;

use App\Domain\Catalog\Support\MinorUnits;
use App\Domain\Orders\DTO\NormalizedOrder;
use App\Domain\Orders\DTO\NormalizedOrderItem;
use App\Domain\Orders\Enums\OrderStatus;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class TrendyolOrderMapper
{
    /** @param array<string, mixed> $payload */
    public function map(array $payload): NormalizedOrder
    {
        $packageId = (string) ($payload['id'] ?? $payload['shipmentPackageId'] ?? '');
        $currency = strtoupper((string) ($payload['currencyCode'] ?? ''));
        $lines = $payload['lines'] ?? null;
        if ($packageId === '' || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || ! is_array($lines) || $lines === []) {
            throw new InvalidArgumentException('Trendyol paket kimliği, para birimi ve satırları gereklidir.');
        }
        $items = array_map(function (array $line): NormalizedOrderItem {
            $quantity = max(1, (int) ($line['quantity'] ?? 0));
            $unit = $this->money($line['lineUnitPrice'] ?? $line['price'] ?? 0);
            $total = array_key_exists('amount', $line) ? $this->money($line['amount']) : $unit * $quantity;

            return new NormalizedOrderItem(
                name: trim((string) ($line['productName'] ?? 'Trendyol ürünü')),
                quantity: $quantity,
                unitPriceAmount: $unit,
                discountAmount: max(0, ($unit * $quantity) - $total),
                taxAmount: 0,
                totalAmount: $total,
                externalItemId: isset($line['lineId']) ? (string) $line['lineId'] : null,
                externalProductId: isset($line['productCode']) ? (string) $line['productCode'] : null,
                externalSku: ($line['merchantSku'] ?? '') !== '' ? (string) $line['merchantSku'] : null,
                externalBarcode: ($line['barcode'] ?? '') !== '' ? (string) $line['barcode'] : null,
            );
        }, $lines);
        $shipping = is_array($payload['shipmentAddress'] ?? null) ? $this->address($payload['shipmentAddress']) : [];
        $invoice = is_array($payload['invoiceAddress'] ?? null) ? $this->address($payload['invoiceAddress']) : null;
        $subtotal = array_sum(array_map(fn (NormalizedOrderItem $item): int => $item->unitPriceAmount * $item->quantity, $items));
        $total = $this->money($payload['packageTotalPrice'] ?? $payload['totalPrice'] ?? 0);
        if ($total === 0) {
            $total = array_sum(array_map(fn (NormalizedOrderItem $item): int => $item->totalAmount, $items));
        }
        $orderDate = (int) ($payload['orderDate'] ?? 0);

        return new NormalizedOrder(
            externalOrderId: $packageId,
            externalOrderNumber: isset($payload['orderNumber']) ? (string) $payload['orderNumber'] : null,
            status: $this->status((string) ($payload['shipmentPackageStatus'] ?? $payload['status'] ?? 'Created')),
            externalStatus: (string) ($payload['shipmentPackageStatus'] ?? $payload['status'] ?? 'Created'),
            currency: $currency,
            subtotalAmount: $subtotal,
            discountAmount: max(0, $subtotal - $total),
            shippingAmount: 0,
            taxAmount: 0,
            totalAmount: $total,
            customer: ['name' => trim((string) ($shipping['first_name'] ?? '').' '.(string) ($shipping['last_name'] ?? ''))],
            shippingAddress: $shipping,
            billingAddress: $invoice,
            orderedAt: $orderDate > 0 ? (new DateTimeImmutable('@'.intdiv($orderDate, 1000)))->setTimezone(new DateTimeZone('UTC')) : new DateTimeImmutable('now', new DateTimeZone('UTC')),
            items: $items,
        );
    }

    private function money(mixed $amount): int
    {
        return MinorUnits::fromDecimal((string) $amount);
    }

    /** @param array<string, mixed> $address @return array<string, mixed> */
    private function address(array $address): array
    {
        return ['first_name' => $address['firstName'] ?? null, 'last_name' => $address['lastName'] ?? null, 'company' => $address['company'] ?? null, 'address_1' => $address['fullAddress'] ?? $address['address1'] ?? null, 'address_2' => $address['address2'] ?? null, 'city' => $address['city'] ?? null, 'state' => $address['district'] ?? null, 'postcode' => $address['postalCode'] ?? null, 'country' => $address['countryCode'] ?? 'TR', 'phone' => $address['phone'] ?? null];
    }

    private function status(string $status): OrderStatus
    {
        return match ($status) {
            'Picking', 'Invoiced' => OrderStatus::Processing,
            'Shipped', 'AtCollectionPoint', 'UnDelivered' => OrderStatus::Shipped,
            'Delivered' => OrderStatus::Delivered,
            'Cancelled', 'UnSupplied' => OrderStatus::Cancelled,
            'Returned' => OrderStatus::Returned,
            default => OrderStatus::Pending,
        };
    }
}
