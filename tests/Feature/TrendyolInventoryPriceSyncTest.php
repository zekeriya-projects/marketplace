<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Exceptions\RetryableSyncException;
use App\Integrations\Trendyol\TrendyolClient;
use App\Jobs\CheckTrendyolInventoryPriceBatchJob;
use App\Jobs\SyncVariantInventoryJob;
use App\Jobs\SyncVariantPriceJob;
use App\Models\ChannelListing;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function syncedTrendyolListing(array $overrides = []): array
{
    $tenant = Tenant::factory()->create();
    $account = trendyolAccount($tenant, ['status' => ChannelAccountStatus::Active, 'credentials_encrypted' => trendyolCredentials()]);
    $product = Product::factory()->for($tenant)->create();
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id, 'barcode' => '8690000999', 'base_price_amount' => 12550, 'currency' => 'TRY']);
    $listing = ChannelListing::query()->create([
        'tenant_id' => $tenant->id, 'channel_account_id' => $account->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
        'external_barcode' => $variant->barcode, 'status' => ChannelListingStatus::Active, ...$overrides,
    ]);

    return compact('tenant', 'account', 'product', 'variant', 'listing');
}

it('submits sellable Trendyol inventory and waits for its batch result', function () {
    Queue::fake();
    ['tenant' => $tenant, 'account' => $account, 'variant' => $variant, 'listing' => $listing] = syncedTrendyolListing();
    $active = Warehouse::factory()->for($tenant)->create(['is_active' => true]);
    $inactive = Warehouse::factory()->for($tenant)->create(['is_active' => false]);
    InventoryItem::query()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $active->id, 'product_variant_id' => $variant->id, 'quantity' => 12, 'reserved_quantity' => 3]);
    InventoryItem::query()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $inactive->id, 'product_variant_id' => $variant->id, 'quantity' => 50, 'reserved_quantity' => 0]);
    $sync = $account->syncOperations()->create(['tenant_id' => $tenant->id, 'operation' => 'inventory_push', 'entity_type' => $listing->getMorphClass(), 'entity_id' => $listing->id]);
    Http::fake(['*/price-and-inventory' => Http::response(['batchRequestId' => 'stock-batch'])]);

    (new SyncVariantInventoryJob($sync->id))->handle();

    expect($sync->fresh()->status)->toBe(SyncOperationStatus::Running)->and($sync->fresh()->context['batch_request_id'])->toBe('stock-batch');
    Queue::assertPushed(CheckTrendyolInventoryPriceBatchJob::class);
    Http::assertSent(fn (Request $request): bool => $request['items'][0] === ['barcode' => '8690000999', 'quantity' => 9]);
});

it('sends exact Trendyol prices and completes the batch safely', function () {
    Queue::fake();
    ['tenant' => $tenant, 'account' => $account, 'listing' => $listing] = syncedTrendyolListing();
    $sync = $account->syncOperations()->create(['tenant_id' => $tenant->id, 'operation' => 'price_push', 'entity_type' => $listing->getMorphClass(), 'entity_id' => $listing->id]);
    Http::fake(['*/price-and-inventory' => Http::response(['batchRequestId' => 'price-batch'])]);
    (new SyncVariantPriceJob($sync->id))->handle();
    Http::assertSent(fn (Request $request): bool => $request['items'][0]['salePrice'] === '125.50' && $request['items'][0]['listPrice'] === '125.50');

    Http::fake(['*/batch-requests/price-batch' => Http::response(['status' => 'COMPLETED', 'failedItemCount' => 0, 'items' => [['status' => 'SUCCESS']]])]);
    (new CheckTrendyolInventoryPriceBatchJob($sync->id))->handle(app(TrendyolClient::class));

    expect($sync->fresh()->status)->toBe(SyncOperationStatus::Succeeded)->and($listing->fresh()->channel_price_amount)->toBe(12550)->and($listing->fresh()->last_synced_at)->not->toBeNull();
});

it('records a sanitized Trendyol batch rejection', function () {
    ['tenant' => $tenant, 'account' => $account, 'listing' => $listing] = syncedTrendyolListing();
    $sync = $account->syncOperations()->create(['tenant_id' => $tenant->id, 'operation' => 'inventory_push', 'entity_type' => $listing->getMorphClass(), 'entity_id' => $listing->id, 'status' => SyncOperationStatus::Running, 'context' => ['batch_request_id' => 'failed-batch']]);
    Http::fake(['*' => Http::response(['status' => 'COMPLETED', 'failedItemCount' => 1, 'items' => [['status' => 'FAILURE', 'failureReasons' => ['raw secret response']]]])]);

    (new CheckTrendyolInventoryPriceBatchJob($sync->id))->handle(app(TrendyolClient::class));

    expect($sync->fresh()->status)->toBe(SyncOperationStatus::Failed)->and($sync->fresh()->safe_error_message)->not->toContain('raw secret response');
});

it('rate limits Trendyol jobs and retries provider throttling', function () {
    ['tenant' => $tenant, 'account' => $account, 'listing' => $listing] = syncedTrendyolListing();
    $sync = $account->syncOperations()->create(['tenant_id' => $tenant->id, 'operation' => 'inventory_push', 'entity_type' => $listing->getMorphClass(), 'entity_id' => $listing->id]);
    $job = new SyncVariantInventoryJob($sync->id);
    Http::fake(['*' => Http::response([], 429)]);

    expect($job->middleware())->toHaveCount(1)->and($job->middleware()[0])->toBeInstanceOf(RateLimitedWithRedis::class);
    expect(fn () => $job->handle())->toThrow(RetryableSyncException::class);
});
