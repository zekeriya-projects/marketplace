<?php

declare(strict_types=1);

namespace App\Integrations\Ticimax;

use App\Domain\Catalog\Support\MinorUnits;
use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Integrations\Contracts\ChannelConnector;
use App\Integrations\Contracts\DTO\ChannelRequest;
use App\Integrations\Contracts\Results\SyncResult;
use App\Integrations\Ticimax\DTO\TicimaxCredentials;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use RuntimeException;

final class TicimaxConnector implements ChannelConnector
{
    public function __construct(private readonly TicimaxClient $client) {}

    public function testConnection(ChannelRequest $request): SyncResult
    {
        $account = $this->account($request, false);
        if ($account === null) {
            return $this->notFound('Ticimax hesabı bulunamadı.');
        }
        try {
            return $this->client->testConnection(TicimaxCredentials::fromAccount($account));
        } catch (RuntimeException) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'Ticimax kimlik bilgileri yapılandırılmamış.', 'credentials_missing');
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

    public function pullOrders(ChannelRequest $request): SyncResult
    {
        return $this->unsupported();
    }

    public function pullOrder(ChannelRequest $request): SyncResult
    {
        return $this->unsupported();
    }

    public function updateOrderStatus(ChannelRequest $request): SyncResult
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

        return $this->client->updateInventory(TicimaxCredentials::fromAccount($account), (int) $listing->external_variant_id, max(0, $quantity));
    }

    public function updatePrice(ChannelRequest $request): SyncResult
    {
        $resolved = $this->listing($request);
        if ($resolved instanceof SyncResult) {
            return $resolved;
        }
        [$account, $listing] = $resolved;
        $currencyId = data_get($listing->metadata, 'ticimax_currency_id');
        if (! is_numeric($currencyId)) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'Ticimax listelemesinde para birimi kimliği eksik.', 'currency_mapping_missing');
        }

        return $this->client->updateVariant(TicimaxCredentials::fromAccount($account), [
            'ID' => (int) $listing->external_variant_id,
            'SatisFiyati' => MinorUnits::toDecimal($listing->variant->base_price_amount),
            'ParaBirimiID' => (int) $currencyId,
        ], ['SatisFiyatiGuncelle' => true, 'ParaBirimiGuncelle' => true]);
    }

    private function account(ChannelRequest $request, bool $active = true): ?ChannelAccount
    {
        return ChannelAccount::query()->where('tenant_id', $request->tenantId)->when($active, fn ($query) => $query->where('status', ChannelAccountStatus::Active))->whereKey($request->channelAccountId)->whereHas('channel', fn ($query) => $query->where('code', 'ticimax'))->first();
    }

    /** @return array{ChannelAccount, ChannelListing}|SyncResult */
    private function listing(ChannelRequest $request): array|SyncResult
    {
        $account = $this->account($request);
        $listing = $request->entityId === null ? null : ChannelListing::query()->where('tenant_id', $request->tenantId)->where('channel_account_id', $request->channelAccountId)->where('status', ChannelListingStatus::Active)->whereKey($request->entityId)->with('variant')->first();
        if ($account === null || $listing === null || $listing->variant === null || ! ctype_digit((string) $listing->external_product_id) || ! ctype_digit((string) $listing->external_variant_id)) {
            return $this->notFound('Ticimax ürün/varyasyon eşlemesi bulunamadı.');
        }

        return [$account, $listing];
    }

    private function notFound(string $message): SyncResult
    {
        return SyncResult::failure(SyncErrorCategory::NotFound, $message, 'listing_not_found');
    }

    private function unsupported(): SyncResult
    {
        return SyncResult::failure(SyncErrorCategory::Validation, 'Bu Ticimax işlemi henüz desteklenmiyor.', 'not_implemented');
    }
}
