<?php

declare(strict_types=1);

namespace App\Integrations\Hepsiburada;

use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Integrations\Contracts\ChannelConnector;
use App\Integrations\Contracts\DTO\ChannelRequest;
use App\Integrations\Contracts\Results\SyncResult;
use App\Integrations\Hepsiburada\DTO\HepsiburadaCredentials;
use App\Models\ChannelAccount;
use RuntimeException;

final class HepsiburadaConnector implements ChannelConnector
{
    public function __construct(private readonly HepsiburadaClient $client) {}

    public function testConnection(ChannelRequest $request): SyncResult
    {
        $account = ChannelAccount::query()->where('tenant_id', $request->tenantId)->whereKey($request->channelAccountId)->whereHas('channel', fn ($query) => $query->where('code', 'hepsiburada'))->first();
        if ($account === null) {
            return SyncResult::failure(SyncErrorCategory::NotFound, 'Hepsiburada hesabı bulunamadı.', 'account_not_found');
        }

        try {
            return $this->client->testConnection(HepsiburadaCredentials::fromAccount($account));
        } catch (RuntimeException) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'Hepsiburada kimlik bilgileri yapılandırılmadı.', 'credentials_missing');
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
        return $this->unsupported();
    }

    public function updatePrice(ChannelRequest $request): SyncResult
    {
        return $this->unsupported();
    }

    public function pullOrders(ChannelRequest $request): SyncResult
    {
        return $this->unsupported();
    }

    public function updateOrderStatus(ChannelRequest $request): SyncResult
    {
        return $this->unsupported();
    }

    public function pullOrder(ChannelRequest $request): SyncResult
    {
        return $this->unsupported();
    }

    private function unsupported(): SyncResult
    {
        return SyncResult::failure(SyncErrorCategory::Validation, 'Bu Hepsiburada işlemi henüz kullanıma açık değil.', 'not_implemented');
    }
}
