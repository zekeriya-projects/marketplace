<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Orders\DTO\NormalizedOrder;
use App\Domain\Orders\DTO\NormalizedOrderItem;
use App\Domain\Orders\Enums\OrderItemMappingStatus;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class IngestOrder
{
    public function execute(ChannelAccount $account, NormalizedOrder $data): Order
    {
        $this->validate($data);

        return DB::transaction(function () use ($account, $data): Order {
            $lockedAccount = ChannelAccount::query()->where('tenant_id', $account->tenant_id)->lockForUpdate()->findOrFail($account->id);
            $order = Order::query()->firstOrNew(['channel_account_id' => $lockedAccount->id, 'external_order_id' => $data->externalOrderId]);
            $order->fill([
                'tenant_id' => $lockedAccount->tenant_id,
                'external_order_number' => $data->externalOrderNumber,
                'status' => $data->status,
                'external_status' => $data->externalStatus,
                'currency' => strtoupper($data->currency),
                'subtotal_amount' => $data->subtotalAmount,
                'discount_amount' => $data->discountAmount,
                'shipping_amount' => $data->shippingAmount,
                'tax_amount' => $data->taxAmount,
                'total_amount' => $data->totalAmount,
                'customer_snapshot' => $data->customer,
                'shipping_address_snapshot' => $data->shippingAddress,
                'billing_address_snapshot' => $data->billingAddress,
                'ordered_at' => $data->orderedAt,
                'imported_at' => now(),
            ])->save();

            $order->items()->delete();
            foreach ($data->items as $item) {
                [$listing, $mappingStatus] = $this->resolveListing($lockedAccount, $item);
                $order->items()->create([
                    'tenant_id' => $lockedAccount->tenant_id,
                    'product_variant_id' => $listing?->product_variant_id,
                    'channel_listing_id' => $listing?->id,
                    'external_item_id' => $item->externalItemId,
                    'external_product_id' => $item->externalProductId,
                    'external_variant_id' => $item->externalVariantId,
                    'external_sku' => $item->externalSku,
                    'external_barcode' => $item->externalBarcode,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'unit_price_amount' => $item->unitPriceAmount,
                    'discount_amount' => $item->discountAmount,
                    'tax_amount' => $item->taxAmount,
                    'total_amount' => $item->totalAmount,
                    'mapping_status' => $mappingStatus,
                ]);
            }

            return $order->load('items');
        }, 3);
    }

    /** @return array{?ChannelListing, OrderItemMappingStatus} */
    private function resolveListing(ChannelAccount $account, NormalizedOrderItem $item): array
    {
        $base = ChannelListing::query()->where('tenant_id', $account->tenant_id)->where('channel_account_id', $account->id);
        if ($item->externalProductId !== null) {
            $exact = (clone $base)->where('external_product_id', $item->externalProductId)
                ->when($item->externalVariantId === null, fn ($query) => $query->whereNull('external_variant_id'), fn ($query) => $query->where('external_variant_id', $item->externalVariantId))->get();
            if ($exact->count() === 1) {
                return [$exact->first(), OrderItemMappingStatus::Mapped];
            }
            if ($exact->count() > 1) {
                return [null, OrderItemMappingStatus::Ambiguous];
            }
        }

        foreach ([['external_sku', $item->externalSku], ['external_barcode', $item->externalBarcode]] as [$column, $value]) {
            if ($value === null || $value === '') {
                continue;
            }
            $matches = (clone $base)->where($column, $value)->limit(2)->get();
            if ($matches->count() === 1) {
                return [$matches->first(), OrderItemMappingStatus::Mapped];
            }
            if ($matches->count() > 1) {
                return [null, OrderItemMappingStatus::Ambiguous];
            }
        }

        return [null, OrderItemMappingStatus::Unmapped];
    }

    private function validate(NormalizedOrder $data): void
    {
        if (trim($data->externalOrderId) === '' || preg_match('/^[A-Z]{3}$/', strtoupper($data->currency)) !== 1 || $data->items === []) {
            throw new InvalidArgumentException('Normalized order identity, currency, and items are required.');
        }
        foreach ([$data->subtotalAmount, $data->discountAmount, $data->shippingAmount, $data->taxAmount, $data->totalAmount] as $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException('Order monetary amounts cannot be negative.');
            }
        }
        foreach ($data->items as $item) {
            if (! $item instanceof NormalizedOrderItem || trim($item->name) === '' || $item->quantity < 1 || min($item->unitPriceAmount, $item->discountAmount, $item->taxAmount, $item->totalAmount) < 0) {
                throw new InvalidArgumentException('Normalized order item values are invalid.');
            }
        }
    }
}
