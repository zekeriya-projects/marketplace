<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Catalog\Support\MinorUnits;
use App\Integrations\Contracts\Results\SyncResult;
use App\Integrations\Trendyol\DTO\TrendyolCredentials;
use App\Integrations\Trendyol\TrendyolClient;
use App\Models\SyncOperation;

final class PublishTrendyolListingJob extends ChannelSyncJob
{
    protected function execute(SyncOperation $operation): SyncResult
    {
        $listing = $operation->entity()->with(['variant.product'])->firstOrFail();
        $variant = $listing->variant;
        $meta = $listing->metadata ?? [];
        $quantity = (int) $variant->inventoryItems()->whereHas('warehouse', fn ($q) => $q->where('is_active', true))->sum('quantity') - (int) $variant->inventoryItems()->whereHas('warehouse', fn ($q) => $q->where('is_active', true))->sum('reserved_quantity');
        $payload = ['items' => [['barcode' => $variant->barcode, 'title' => $variant->product->name.' '.$variant->name, 'description' => $variant->product->description ?: $variant->product->name, 'productMainId' => $variant->product_id, 'brandId' => (int) $meta['brand_id'], 'categoryId' => (int) $meta['category_id'], 'quantity' => max(0, $quantity), 'stockCode' => $variant->sku, 'origin' => strtoupper((string) $meta['origin']), 'dimensionalWeight' => (float) $meta['dimensional_weight'], 'listPrice' => MinorUnits::toDecimal($variant->base_price_amount), 'salePrice' => MinorUnits::toDecimal($variant->base_price_amount), 'vatRate' => (int) $meta['vat_rate'], 'images' => [['url' => $meta['image_url']]], 'attributes' => $meta['attributes'] ?? []]]];
        $response = app(TrendyolClient::class)->createProducts(TrendyolCredentials::fromAccount($operation->account), $payload);
        if ($response['result']->successful) {
            $listing->update(['metadata' => [...$meta, 'batch_request_id' => $response['batch_id']]]);

            return SyncResult::success(['batch_request_id' => $response['batch_id'], 'awaiting_batch' => true]);
        }

        return $response['result'];
    }

    protected function completeSuccessfulOperation(SyncOperation $operation, SyncResult $result): bool
    {
        $operation->update(['context' => $result->context]);
        CheckTrendyolListingBatchJob::dispatch($operation->id)->delay(now()->addSeconds(10));

        return false;
    }
}
