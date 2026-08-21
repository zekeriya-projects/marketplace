<?php

declare(strict_types=1);

use App\Domain\Channels\Actions\PublishTrendyolListing;
use App\Domain\Channels\Actions\QueueWooCommerceProductSync;
use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Channels\Enums\PublicationStatus;
use App\Jobs\PublishTrendyolListingJob;
use App\Jobs\PublishWooCommerceProductJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncOperation;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;

it('returns a typed already-active result for repeated WooCommerce publication requests', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $account = ChannelAccount::factory()->for($tenant)->create(['channel_id' => Channel::query()->where('code', 'woocommerce')->sole()->id, 'status' => ChannelAccountStatus::Active]);
    $product = Product::factory()->for($tenant)->create();
    ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id]);
    $action = app(QueueWooCommerceProductSync::class);

    expect($action->executeForAccount($product, $account)->status)->toBe(PublicationStatus::Queued)
        ->and($action->executeForAccount($product, $account)->status)->toBe(PublicationStatus::AlreadyActive)
        ->and(SyncOperation::query()->count())->toBe(1);
    Queue::assertPushed(PublishWooCommerceProductJob::class, 1);
});

it('returns a typed already-active result for repeated Trendyol publication requests', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $account = ChannelAccount::factory()->for($tenant)->create(['channel_id' => Channel::query()->where('code', 'trendyol')->sole()->id, 'status' => ChannelAccountStatus::Active]);
    $product = Product::factory()->for($tenant)->create();
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id, 'sku' => 'T-1', 'barcode' => '86901', 'currency' => 'TRY']);
    $payload = ['variant_id' => $variant->id, 'brand_id' => 1, 'category_id' => 2, 'image_url' => 'https://cdn.example.com/p.jpg', 'vat_rate' => 20, 'dimensional_weight' => 1, 'origin' => 'TR', 'attributes' => []];
    $action = app(PublishTrendyolListing::class);

    expect($action->queue($account, $payload)->status)->toBe(PublicationStatus::Queued)
        ->and($action->queue($account, $payload)->status)->toBe(PublicationStatus::AlreadyActive)
        ->and(SyncOperation::query()->count())->toBe(1);
    Queue::assertPushed(PublishTrendyolListingJob::class, 1);
});

it('keeps the partial unique index as the final concurrency boundary', function () {
    $tenant = Tenant::factory()->create();
    $account = ChannelAccount::factory()->for($tenant)->create(['channel_id' => Channel::query()->where('code', 'woocommerce')->sole()->id]);
    $product = Product::factory()->for($tenant)->create();
    $values = ['tenant_id' => $tenant->id, 'operation' => 'product_publish', 'entity_type' => $product->getMorphClass(), 'entity_id' => $product->id, 'status' => 'pending'];
    $account->syncOperations()->create($values);

    expect(fn () => $account->syncOperations()->create($values))->toThrow(QueryException::class);
});
