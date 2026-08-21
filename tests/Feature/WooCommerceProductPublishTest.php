<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\CatalogStatus;
use App\Domain\Channels\Actions\ImportWooCommerceProduct;
use App\Domain\Channels\Actions\QueueWooCommerceProductSync;
use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Integrations\ConnectorManager;
use App\Integrations\WooCommerce\WooCommerceCatalogImporter;
use App\Integrations\WooCommerce\WooCommerceClient;
use App\Integrations\WooCommerce\WooCommerceConnector;
use App\Integrations\WooCommerce\WooCommerceUrlGuard;
use App\Jobs\PublishWooCommerceProductJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function publishableWooFixture(int $variantCount = 1): array
{
    $tenant = Tenant::factory()->create();
    $account = ChannelAccount::factory()->for($tenant)->create([
        'channel_id' => Channel::query()->where('code', 'woocommerce')->sole()->id,
        'status' => ChannelAccountStatus::Active,
        'settings' => ['currency' => 'TRY'],
        'credentials_encrypted' => [
            'store_url' => 'https://shop.example.com',
            'consumer_key' => 'ck_'.str_repeat('a', 40),
            'consumer_secret' => 'cs_'.str_repeat('b', 40),
        ],
    ]);
    $product = Product::factory()->for($tenant)->create(['name' => 'Merkez Ürün', 'status' => CatalogStatus::Active]);

    foreach (range(1, $variantCount) as $index) {
        ProductVariant::factory()->for($product)->create([
            'tenant_id' => $tenant->id,
            'name' => "Varyant {$index}",
            'sku' => "SAAS-{$index}",
            'barcode' => "86900000000{$index}",
            'base_price_amount' => 12550 + $index,
            'currency' => 'TRY',
            'option_values' => $variantCount > 1 ? ['Renk' => $index === 1 ? 'Siyah' : 'Beyaz'] : null,
        ]);
    }

    return compact('tenant', 'account', 'product');
}

function registerWooPublishConnector(): void
{
    $guard = Mockery::mock(WooCommerceUrlGuard::class);
    $guard->shouldReceive('assertSafe')->andReturnNull();
    $client = new WooCommerceClient($guard);
    app(ConnectorManager::class)->register('woocommerce', new WooCommerceConnector(
        $client,
        new WooCommerceCatalogImporter($client, app(ImportWooCommerceProduct::class)),
    ));
}

it('queues one idempotent WooCommerce product publication for every active account', function () {
    Queue::fake();
    ['product' => $product] = publishableWooFixture(2);

    expect(app(QueueWooCommerceProductSync::class)->execute($product))->toBe(1)
        ->and(app(QueueWooCommerceProductSync::class)->execute($product))->toBe(0)
        ->and(ChannelListing::query()->count())->toBe(2)
        ->and(ChannelListing::query()->where('status', ChannelListingStatus::Pending)->count())->toBe(2);
    Queue::assertPushed(PublishWooCommerceProductJob::class, 1);
});

it('publishes a simple canonical product and persists its WooCommerce mapping', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(['id' => 501], 201)]);
    registerWooPublishConnector();
    ['account' => $account, 'product' => $product] = publishableWooFixture();
    app(QueueWooCommerceProductSync::class)->execute($product);
    $operation = $account->syncOperations()->where('operation', 'product_publish')->sole();

    (new PublishWooCommerceProductJob($operation->id))->handle();

    $listing = ChannelListing::query()->sole();
    expect($operation->fresh()->status)->toBe(SyncOperationStatus::Succeeded)
        ->and($listing->fresh()->status)->toBe(ChannelListingStatus::Active)
        ->and($listing->external_product_id)->toBe('501')
        ->and($listing->external_variant_id)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://shop.example.com/wp-json/wc/v3/products'
        && $request['type'] === 'simple'
        && $request['sku'] === 'SAAS-1'
        && $request['regular_price'] === '125.51');
});

it('publishes a variable product parent and maps every WooCommerce variation', function () {
    Queue::fake();
    $variationId = 700;
    Http::fake(function (Request $request) use (&$variationId) {
        if ($request->url() === 'https://shop.example.com/wp-json/wc/v3/products') {
            return Http::response(['id' => 600], 201);
        }

        return Http::response(['id' => ++$variationId], 201);
    });
    registerWooPublishConnector();
    ['account' => $account, 'product' => $product] = publishableWooFixture(2);
    app(QueueWooCommerceProductSync::class)->execute($product);
    $operation = $account->syncOperations()->where('operation', 'product_publish')->sole();

    (new PublishWooCommerceProductJob($operation->id))->handle();

    expect($operation->fresh()->status)->toBe(SyncOperationStatus::Succeeded)
        ->and(ChannelListing::query()->where('external_product_id', '600')->count())->toBe(2)
        ->and(ChannelListing::query()->whereNotNull('external_variant_id')->count())->toBe(2)
        ->and(ChannelListing::query()->where('status', ChannelListingStatus::Active)->count())->toBe(2);
    Http::assertSentCount(3);
});
