<?php

declare(strict_types=1);

namespace App\Integrations\WooCommerce;

use App\Domain\Catalog\Support\MinorUnits;
use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Support\OrderStatusCapabilities;
use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Integrations\Contracts\ChannelConnector;
use App\Integrations\Contracts\DTO\ChannelRequest;
use App\Integrations\Contracts\Results\SyncResult;
use App\Integrations\WooCommerce\DTO\WooCommerceCredentials;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Storage;

final class WooCommerceConnector implements ChannelConnector
{
    public function __construct(private readonly WooCommerceClient $client, private readonly WooCommerceCatalogImporter $catalogImporter, private readonly ?WooCommerceOrderImporter $orderImporter = null) {}

    public function testConnection(ChannelRequest $request): SyncResult
    {
        $account = ChannelAccount::query()->where('tenant_id', $request->tenantId)->whereKey($request->channelAccountId)->with('channel')->first();
        if ($account === null || $account->channel->code !== 'woocommerce') {
            return SyncResult::failure(SyncErrorCategory::NotFound, 'The WooCommerce account could not be found.', 'account_not_found');
        }

        try {
            $credentials = WooCommerceCredentials::fromAccount($account);
            $result = $this->client->testConnection($credentials);
            if ($result->successful) {
                $currency = $this->client->pullCurrentCurrency($credentials);
                if ($currency['result']->successful && $currency['currency'] !== null) {
                    $account->update(['settings' => [...($account->settings ?? []), 'currency' => $currency['currency']]]);
                }
            }

            return $result;
        } catch (\RuntimeException) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'WooCommerce credentials have not been configured.', 'credentials_missing');
        }
    }

    public function pullProducts(ChannelRequest $request): SyncResult
    {
        $account = ChannelAccount::query()->where('tenant_id', $request->tenantId)->whereKey($request->channelAccountId)->with('channel')->first();
        if ($account === null || $account->channel->code !== 'woocommerce') {
            return SyncResult::failure(SyncErrorCategory::NotFound, 'The WooCommerce account could not be found.', 'account_not_found');
        }

        return $this->catalogImporter->importPage($account, max(1, (int) ($request->options['page'] ?? 1)));
    }

    public function pushProduct(ChannelRequest $request): SyncResult
    {
        $account = ChannelAccount::query()
            ->where('tenant_id', $request->tenantId)
            ->where('status', ChannelAccountStatus::Active)
            ->whereKey($request->channelAccountId)
            ->whereHas('channel', fn ($query) => $query->where('code', 'woocommerce'))
            ->first();
        $product = $request->entityId === null ? null : Product::query()
            ->where('tenant_id', $request->tenantId)
            ->with(['variants.inventoryItems.warehouse', 'images'])
            ->find($request->entityId);

        if ($account === null || $product === null) {
            return SyncResult::failure(SyncErrorCategory::NotFound, 'The WooCommerce account or product could not be found.', 'product_not_found');
        }
        if ($product->disable_external_sync || $product->variants->isEmpty()) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'The product is not eligible for external synchronization.', 'product_not_eligible');
        }

        $credentials = WooCommerceCredentials::fromAccount($account);
        $storeCurrency = strtoupper((string) data_get($account->settings, 'currency'));
        if ($storeCurrency === '') {
            $currency = $this->client->pullCurrentCurrency($credentials);
            if ($currency['result']->successful && $currency['currency'] !== null) {
                $storeCurrency = $currency['currency'];
                $account->update(['settings' => [...($account->settings ?? []), 'currency' => $storeCurrency]]);
            }
        }
        if ($storeCurrency === '' || $product->variants->contains(fn (ProductVariant $variant) => $variant->currency !== $storeCurrency)) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'Product currency does not match the WooCommerce store currency.', 'currency_mismatch');
        }

        $listings = ChannelListing::query()
            ->where('tenant_id', $request->tenantId)
            ->where('channel_account_id', $account->id)
            ->where('product_id', $product->id)
            ->get()
            ->keyBy('product_variant_id');
        $parentId = $listings->pluck('external_product_id')->filter()->first()
            ?? $listings->pluck('metadata')->filter()->map(fn (array $metadata) => $metadata['pending_external_product_id'] ?? null)->filter()->first();
        $variable = $product->variants->count() > 1;
        $parentPayload = $this->parentPayload($product, $variable);

        if ($parentId === null) {
            $created = $this->client->createProduct($credentials, $variable ? $parentPayload : [...$parentPayload, ...$this->variantPayload($product->variants->first(), false)]);
            if (! $created['result']->successful) {
                $this->markListingsFailed($listings);

                return $created['result'];
            }
            $parentId = isset($created['payload']['id']) ? (string) $created['payload']['id'] : null;
            if ($parentId === null) {
                $this->markListingsFailed($listings);

                return SyncResult::failure(SyncErrorCategory::Unknown, 'WooCommerce did not return a product identifier.', 'missing_product_id');
            }
            if ($variable) {
                $listings->each(function (ChannelListing $listing) use ($parentId): void {
                    $listing->update(['metadata' => [...($listing->metadata ?? []), 'pending_external_product_id' => $parentId]]);
                });
            } else {
                $listings->each->update(['external_product_id' => $parentId]);
            }
        } else {
            $updated = $this->client->updateProduct($credentials, $parentId, null, $variable ? $parentPayload : [...$parentPayload, ...$this->variantPayload($product->variants->first(), false)]);
            if (! $updated->successful) {
                $this->markListingsFailed($listings);

                return $updated;
            }
        }

        foreach ($product->variants as $variant) {
            $listing = $listings->get($variant->id);
            if ($listing === null) {
                continue;
            }

            if ($variable) {
                $payload = $this->variantPayload($variant, true);
                if ($listing->external_variant_id === null) {
                    $created = $this->client->createVariation($credentials, $parentId, $payload);
                    if (! $created['result']->successful) {
                        $listing->update(['status' => ChannelListingStatus::Error]);

                        return $created['result'];
                    }
                    $variationId = isset($created['payload']['id']) ? (string) $created['payload']['id'] : null;
                    if ($variationId === null) {
                        $listing->update(['status' => ChannelListingStatus::Error]);

                        return SyncResult::failure(SyncErrorCategory::Unknown, 'WooCommerce did not return a variation identifier.', 'missing_variation_id');
                    }
                    $listing->external_variant_id = $variationId;
                } else {
                    $updated = $this->client->updateProduct($credentials, $parentId, $listing->external_variant_id, $payload);
                    if (! $updated->successful) {
                        $listing->update(['status' => ChannelListingStatus::Error]);

                        return $updated;
                    }
                }
            }

            $listing->update([
                'external_product_id' => $parentId,
                'external_sku' => $variant->sku,
                'external_barcode' => $variant->barcode,
                'status' => ChannelListingStatus::Active,
                'channel_price_amount' => $variant->base_price_amount,
                'currency' => $variant->currency,
                'published_at' => $listing->published_at ?? now(),
                'last_synced_at' => now(),
                'metadata' => ['product_type' => $variable ? 'variable' : 'simple'],
            ]);
        }

        return SyncResult::success(['external_product_id' => $parentId]);
    }

    public function updateInventory(ChannelRequest $request): SyncResult
    {
        $resolved = $this->listing($request);
        if ($resolved instanceof SyncResult) {
            return $resolved;
        }
        [$account, $listing] = $resolved;
        $quantity = (int) $listing->variant->inventoryItems()->whereHas('warehouse', fn ($query) => $query->where('is_active', true))->sum('quantity')
            - (int) $listing->variant->inventoryItems()->whereHas('warehouse', fn ($query) => $query->where('is_active', true))->sum('reserved_quantity');
        $result = $this->client->updateProduct(WooCommerceCredentials::fromAccount($account), $listing->external_product_id, $listing->external_variant_id, ['manage_stock' => true, 'stock_quantity' => max(0, $quantity)]);
        if ($result->successful) {
            $listing->update(['last_synced_at' => now()]);
        }

        return $result;
    }

    public function updatePrice(ChannelRequest $request): SyncResult
    {
        $resolved = $this->listing($request);
        if ($resolved instanceof SyncResult) {
            return $resolved;
        }
        [$account, $listing] = $resolved;
        $storeCurrency = strtoupper((string) data_get($account->settings, 'currency'));
        if ($storeCurrency === '' || $storeCurrency !== $listing->variant->currency) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'Variant currency does not match the WooCommerce store currency.', 'currency_mismatch');
        }
        $result = $this->client->updateProduct(WooCommerceCredentials::fromAccount($account), $listing->external_product_id, $listing->external_variant_id, ['regular_price' => MinorUnits::toDecimal($listing->variant->base_price_amount)]);
        if ($result->successful) {
            $listing->update(['channel_price_amount' => $listing->variant->base_price_amount, 'currency' => $listing->variant->currency, 'last_synced_at' => now()]);
        }

        return $result;
    }

    public function pullOrders(ChannelRequest $request): SyncResult
    {
        $account = ChannelAccount::query()->where('tenant_id', $request->tenantId)->whereKey($request->channelAccountId)->with('channel')->first();
        if ($account === null || $account->channel->code !== 'woocommerce') {
            return SyncResult::failure(SyncErrorCategory::NotFound, 'The WooCommerce account could not be found.', 'account_not_found');
        }

        if ($this->orderImporter === null) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'The WooCommerce order importer is unavailable.', 'order_importer_unavailable');
        }

        return $this->orderImporter->importPage($account, max(1, (int) ($request->options['page'] ?? 1)), (string) ($request->options['from'] ?? ''), (string) ($request->options['to'] ?? ''));
    }

    public function updateOrderStatus(ChannelRequest $request): SyncResult
    {
        $account = ChannelAccount::query()->where('tenant_id', $request->tenantId)->where('status', ChannelAccountStatus::Active)->whereKey($request->channelAccountId)->whereHas('channel', fn ($query) => $query->where('code', 'woocommerce'))->first();
        $order = $request->entityId === null ? null : Order::query()->where('tenant_id', $request->tenantId)->where('channel_account_id', $request->channelAccountId)->find($request->entityId);
        $status = OrderStatus::tryFrom((string) ($request->options['status'] ?? ''));
        $externalStatus = $account === null || $status === null ? null : app(OrderStatusCapabilities::class)->externalStatus($account, $status);

        if ($account === null || $order === null) {
            return SyncResult::failure(SyncErrorCategory::NotFound, 'WooCommerce hesabı veya siparişi bulunamadı.', 'order_not_found');
        }
        if ($externalStatus === null) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'Sipariş durumu WooCommerce tarafından desteklenmiyor.', 'unsupported_order_status');
        }

        $result = $this->client->updateOrderStatus(WooCommerceCredentials::fromAccount($account), $order->external_order_id, $externalStatus);

        return $result->successful ? SyncResult::success(['external_status' => $externalStatus]) : $result;
    }

    public function pullOrder(ChannelRequest $request): SyncResult
    {
        $account = ChannelAccount::query()->where('tenant_id', $request->tenantId)->whereKey($request->channelAccountId)->whereHas('channel', fn ($query) => $query->where('code', 'woocommerce'))->first();
        $order = $request->entityId === null ? null : Order::query()->where('tenant_id', $request->tenantId)->where('channel_account_id', $request->channelAccountId)->find($request->entityId);
        if ($account === null || $order === null || $this->orderImporter === null) {
            return SyncResult::failure(SyncErrorCategory::NotFound, 'WooCommerce hesabı veya siparişi bulunamadı.', 'order_not_found');
        }

        return $this->orderImporter->importOne($account, $order->external_order_id);
    }

    /** @return array<string, mixed> */
    private function parentPayload(Product $product, bool $variable): array
    {
        $payload = [
            'name' => $product->name,
            'type' => $variable ? 'variable' : 'simple',
            'status' => $product->status->value === 'active' ? 'publish' : 'draft',
            'description' => $product->description ?? '',
        ];
        $images = $product->images->map(fn ($image): array => [
            'src' => str_starts_with(Storage::disk($image->disk)->url($image->path), 'http')
                ? Storage::disk($image->disk)->url($image->path)
                : rtrim((string) config('app.url'), '/').Storage::disk($image->disk)->url($image->path),
            'alt' => $image->alt_text ?? $product->name,
        ])->values()->all();
        if ($images !== []) {
            $payload['images'] = $images;
        }
        if ($variable) {
            $attributes = [];
            foreach ($product->variants as $variant) {
                foreach ($variant->option_values ?? [] as $name => $option) {
                    $attributes[$name][] = $option;
                }
            }
            $payload['attributes'] = collect($attributes)->map(fn (array $options, string $name): array => [
                'name' => $name,
                'visible' => true,
                'variation' => true,
                'options' => array_values(array_unique($options)),
            ])->values()->all();
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function variantPayload(ProductVariant $variant, bool $includeAttributes): array
    {
        $quantity = $variant->inventoryItems
            ->filter(fn ($item) => $item->warehouse?->is_active)
            ->sum(fn ($item) => $item->quantity - $item->reserved_quantity);
        $payload = [
            'sku' => $variant->sku ?? '',
            'regular_price' => MinorUnits::toDecimal($variant->base_price_amount),
            'manage_stock' => true,
            'stock_quantity' => max(0, (int) $quantity),
            'status' => $variant->status->value === 'active' ? 'publish' : 'private',
        ];
        if ($variant->barcode !== null) {
            $payload['global_unique_id'] = $variant->barcode;
        }
        if ($includeAttributes) {
            $payload['attributes'] = collect($variant->option_values ?? [])->map(
                fn (string $option, string $name): array => ['name' => $name, 'option' => $option],
            )->values()->all();
        }

        return $payload;
    }

    private function markListingsFailed($listings): void
    {
        $listings->each->update(['status' => ChannelListingStatus::Error]);
    }

    /** @return array{ChannelAccount, ChannelListing}|SyncResult */
    private function listing(ChannelRequest $request): array|SyncResult
    {
        $account = ChannelAccount::query()->where('tenant_id', $request->tenantId)->where('status', ChannelAccountStatus::Active)->whereKey($request->channelAccountId)->with('channel')->first();
        $listing = $request->entityId === null ? null : ChannelListing::query()->where('tenant_id', $request->tenantId)->where('channel_account_id', $request->channelAccountId)->where('status', ChannelListingStatus::Active)->whereKey($request->entityId)->with('variant')->first();
        if ($account === null || $account->channel->code !== 'woocommerce' || $listing === null || $listing->variant === null) {
            return SyncResult::failure(SyncErrorCategory::NotFound, 'The WooCommerce listing could not be found.', 'listing_not_found');
        }

        return [$account, $listing];
    }
}
