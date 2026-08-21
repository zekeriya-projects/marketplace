<?php

declare(strict_types=1);

namespace App\Integrations\Trendyol;

use App\Domain\Orders\Actions\ApplyOrderInventory;
use App\Domain\Orders\Actions\IngestOrder;
use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Integrations\Contracts\Results\SyncResult;
use App\Integrations\Trendyol\DTO\TrendyolCredentials;
use App\Models\ChannelAccount;
use Throwable;

final class TrendyolOrderImporter
{
    public function __construct(private readonly TrendyolClient $client, private readonly TrendyolOrderMapper $mapper, private readonly IngestOrder $ingestOrder, private readonly ApplyOrderInventory $applyInventory) {}

    public function importPage(ChannelAccount $account, int $fromMilliseconds, int $toMilliseconds, ?string $cursor): SyncResult
    {
        try {
            $response = $this->client->pullOrders(TrendyolCredentials::fromAccount($account), $fromMilliseconds, $toMilliseconds, $cursor);
        } catch (Throwable) {
            return SyncResult::failure(SyncErrorCategory::Validation, 'Trendyol kimlik bilgileri yapılandırılmamış.', 'credentials_missing');
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

        return SyncResult::success(['imported' => $imported, 'failed' => $failed, 'unmapped' => $unmapped, 'next_cursor' => $response->nextCursor]);
    }
}
