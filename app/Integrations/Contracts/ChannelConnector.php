<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Integrations\Contracts\DTO\ChannelRequest;
use App\Integrations\Contracts\Results\SyncResult;

interface ChannelConnector
{
    public function testConnection(ChannelRequest $request): SyncResult;

    public function pullProducts(ChannelRequest $request): SyncResult;

    public function pushProduct(ChannelRequest $request): SyncResult;

    public function updateInventory(ChannelRequest $request): SyncResult;

    public function updatePrice(ChannelRequest $request): SyncResult;

    public function pullOrders(ChannelRequest $request): SyncResult;

    public function pullOrder(ChannelRequest $request): SyncResult;

    public function updateOrderStatus(ChannelRequest $request): SyncResult;
}
