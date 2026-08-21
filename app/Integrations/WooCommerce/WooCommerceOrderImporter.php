<?php

declare(strict_types=1);

namespace App\Integrations\WooCommerce;

use App\Domain\Orders\Actions\ApplyOrderInventory;
use App\Domain\Orders\Actions\IngestOrder;
use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Integrations\Contracts\Results\SyncResult;
use App\Integrations\WooCommerce\DTO\WooCommerceCredentials;
use App\Models\ChannelAccount;
use Throwable;

final class WooCommerceOrderImporter
{
    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly WooCommerceOrderMapper $mapper,
        private readonly IngestOrder $ingestOrder,
        private readonly ApplyOrderInventory $applyInventory,
    ) {}

    public function importPage(ChannelAccount $account, int $page, string $from, string $to): SyncResult
    {
        try {
            $response = $this->client->pullOrdersPage(WooCommerceCredentials::fromAccount($account), $page, $from, $to);
        } catch (Throwable) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'WooCommerce credentials have not been configured.', 'credentials_missing');
        }
        if (! $response->result->successful) {
            return $response->result;
        }

        $imported = $failed = $unmapped = 0;
        foreach ($response->orders as $payload) {
            try {
                $order = $this->ingestOrder->execute($account, $this->mapper->map($payload));
                $unmapped += $order->items->where('mapping_status', '!=', 'mapped')->count();
                $this->applyInventory->execute($order);
                $imported++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return SyncResult::success(['total_pages' => $response->totalPages, 'imported' => $imported, 'failed' => $failed, 'unmapped' => $unmapped]);
    }

    public function importOne(ChannelAccount $account, string $externalOrderId): SyncResult
    {
        try {
            $response = $this->client->pullOrder(WooCommerceCredentials::fromAccount($account), $externalOrderId);
            if (! $response['result']->successful) {
                return $response['result'];
            }
            $order = $this->ingestOrder->execute($account, $this->mapper->map($response['order']));
            $this->applyInventory->execute($order);

            return SyncResult::success(['external_status' => $order->external_status, 'status' => $order->status->value]);
        } catch (Throwable) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'WooCommerce siparişi güncellenemedi.', 'order_refresh_failed');
        }
    }
}
