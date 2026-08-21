<?php

declare(strict_types=1);

namespace App\Integrations\Trendyol;

use App\Domain\Catalog\Support\MinorUnits;
use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Integrations\Contracts\ChannelConnector;
use App\Integrations\Contracts\DTO\ChannelRequest;
use App\Integrations\Contracts\Results\SyncResult;
use App\Integrations\Trendyol\DTO\TrendyolCredentials;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\Order;
use RuntimeException;

final class TrendyolConnector implements ChannelConnector
{
    public function __construct(private readonly TrendyolClient $client, private readonly ?TrendyolOrderImporter $orderImporter = null) {}

    public function testConnection(ChannelRequest $request): SyncResult
    {
        $account = ChannelAccount::query()->where('tenant_id', $request->tenantId)->whereKey($request->channelAccountId)->with('channel')->first();
        if ($account === null || $account->channel->code !== 'trendyol') {
            return SyncResult::failure(SyncErrorCategory::NotFound, 'The Trendyol account could not be found.', 'account_not_found');
        }
        try {
            return $this->client->testConnection(TrendyolCredentials::fromAccount($account));
        } catch (RuntimeException) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'Trendyol credentials have not been configured.', 'credentials_missing');
        }
    }

    public function pullProducts(ChannelRequest $request): SyncResult
    {
        return $this->unsupported();
    }

    public function pushProduct(ChannelRequest $request): SyncResult
    {
        return $this->unsupported();
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

        return $this->client->updatePriceAndInventory(TrendyolCredentials::fromAccount($account), ['barcode' => $listing->external_barcode, 'quantity' => min(20000, max(0, $quantity))]);
    }

    public function updatePrice(ChannelRequest $request): SyncResult
    {
        $resolved = $this->listing($request);
        if ($resolved instanceof SyncResult) {
            return $resolved;
        }
        [$account, $listing] = $resolved;
        if ($listing->variant->currency !== 'TRY') {
            return SyncResult::failure(SyncErrorCategory::Validation, 'Trendyol fiyatı TRY para biriminde olmalıdır.', 'currency_mismatch');
        }
        $price = MinorUnits::toDecimal($listing->variant->base_price_amount);

        return $this->client->updatePriceAndInventory(TrendyolCredentials::fromAccount($account), ['barcode' => $listing->external_barcode, 'salePrice' => $price, 'listPrice' => $price]);
    }

    public function pullOrders(ChannelRequest $request): SyncResult
    {
        $account = ChannelAccount::query()->where('tenant_id', $request->tenantId)->whereKey($request->channelAccountId)->with('channel')->first();
        if ($account === null || $account->channel->code !== 'trendyol' || $this->orderImporter === null) {
            return SyncResult::failure(SyncErrorCategory::NotFound, 'Trendyol hesabı veya sipariş aktarıcısı bulunamadı.', 'order_importer_unavailable');
        }

        return $this->orderImporter->importPage($account, (int) ($request->options['from'] ?? 0), (int) ($request->options['to'] ?? 0), isset($request->options['cursor']) ? (string) $request->options['cursor'] : null);
    }

    public function updateOrderStatus(ChannelRequest $request): SyncResult
    {
        $account = ChannelAccount::query()->where('tenant_id', $request->tenantId)->where('status', ChannelAccountStatus::Active)->whereKey($request->channelAccountId)->whereHas('channel', fn ($query) => $query->where('code', 'trendyol'))->first();
        $order = $request->entityId === null ? null : Order::query()->where('tenant_id', $request->tenantId)->where('channel_account_id', $request->channelAccountId)->with('items')->find($request->entityId);
        if ($account === null || $order === null) {
            return SyncResult::failure(SyncErrorCategory::NotFound, 'Trendyol hesabı veya siparişi bulunamadı.', 'order_not_found');
        }
        if (($request->options['status'] ?? null) !== 'processing') {
            return SyncResult::failure(SyncErrorCategory::Validation, 'Trendyol için panelden yalnızca Hazırlanıyor durumu gönderilebilir.', 'unsupported_order_status');
        }
        $lines = $order->items->map(function ($item): ?array {
            return ctype_digit((string) $item->external_item_id) ? ['lineId' => (int) $item->external_item_id, 'quantity' => $item->quantity] : null;
        })->filter()->values()->all();
        if ($lines === [] || count($lines) !== $order->items->count()) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'Trendyol sipariş satırı kimlikleri eksik.', 'order_line_ids_missing');
        }

        $result = $this->client->updatePackageStatus(TrendyolCredentials::fromAccount($account), $order->external_order_id, 'Picking', $lines);

        return $result->successful ? SyncResult::success(['external_status' => 'Picking']) : $result;
    }

    public function pullOrder(ChannelRequest $request): SyncResult
    {
        return SyncResult::failure(SyncErrorCategory::Validation, 'Trendyol tekil sipariş yenileme işlemi desteklenmiyor.', 'not_implemented');
    }

    private function unsupported(): SyncResult
    {
        return SyncResult::failure(SyncErrorCategory::Validation, 'This Trendyol operation is not available yet.', 'not_implemented');
    }

    /** @return array{ChannelAccount, ChannelListing}|SyncResult */
    private function listing(ChannelRequest $request): array|SyncResult
    {
        $account = ChannelAccount::query()->where('tenant_id', $request->tenantId)->where('status', ChannelAccountStatus::Active)->whereKey($request->channelAccountId)->with('channel')->first();
        $listing = $request->entityId === null ? null : ChannelListing::query()->where('tenant_id', $request->tenantId)->where('channel_account_id', $request->channelAccountId)->where('status', ChannelListingStatus::Active)->whereKey($request->entityId)->with('variant')->first();
        if ($account === null || $account->channel->code !== 'trendyol' || $listing === null || $listing->variant === null || $listing->external_barcode === null) {
            return SyncResult::failure(SyncErrorCategory::NotFound, 'Trendyol listelemesi bulunamadı.', 'listing_not_found');
        }

        return [$account, $listing];
    }
}
