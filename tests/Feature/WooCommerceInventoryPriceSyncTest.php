<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Channels\Actions\ImportWooCommerceProduct;
use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Inventory\Actions\AdjustInventory;
use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Events\VariantPriceChanged;
use App\Integrations\ConnectorManager;
use App\Integrations\WooCommerce\WooCommerceCatalogImporter;
use App\Integrations\WooCommerce\WooCommerceClient;
use App\Integrations\WooCommerce\WooCommerceConnector;
use App\Integrations\WooCommerce\WooCommerceUrlGuard;
use App\Jobs\SyncVariantInventoryJob;
use App\Jobs\SyncVariantPriceJob;
use App\Models\ChannelListing;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncOperation;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function syncedWooListing(array $overrides = []): array
{
    $tenant = Tenant::factory()->create();
    $account = wooAccount($tenant, ['status' => ChannelAccountStatus::Active, 'settings' => ['currency' => 'TRY'], 'credentials_encrypted' => wooCredentialsPayload(['store_url' => 'https://shop.example.com'])]);
    $product = Product::factory()->for($tenant)->create();
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id, 'base_price_amount' => 12550, 'currency' => 'TRY']);
    $listing = ChannelListing::query()->create([
        'tenant_id' => $tenant->id, 'channel_account_id' => $account->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
        'external_product_id' => '55', 'external_variant_id' => '77', 'status' => ChannelListingStatus::Active, ...$overrides,
    ]);

    return compact('tenant', 'account', 'product', 'variant', 'listing');
}

function registerFakeSafeWooConnector(): void
{
    $guard = Mockery::mock(WooCommerceUrlGuard::class);
    $guard->shouldReceive('assertSafe')->andReturnNull();
    $client = new WooCommerceClient($guard);
    app(ConnectorManager::class)->register('woocommerce', new WooCommerceConnector($client, new WooCommerceCatalogImporter($client, app(ImportWooCommerceProduct::class))));
}

it('queues one inventory synchronization after a committed stock adjustment', function () {
    Queue::fake();
    ['tenant' => $tenant, 'variant' => $variant, 'listing' => $listing] = syncedWooListing();
    $warehouse = Warehouse::factory()->for($tenant)->create();
    $user = tenantMember($tenant, TenantRole::Operator);

    app(AdjustInventory::class)->execute($tenant, $warehouse, $variant, 4, 'Initial stock', $user);

    $sync = SyncOperation::query()->where('operation', 'inventory_push')->sole();
    expect($sync->entity_id)->toBe($listing->id)->and($sync->tenant_id)->toBe($tenant->id);
    Queue::assertPushed(SyncVariantInventoryJob::class, 1);
});

it('deduplicates pending price synchronization for the same listing', function () {
    Queue::fake();
    ['tenant' => $tenant, 'product' => $product, 'variant' => $variant] = syncedWooListing();

    app(SaveProduct::class)->update($tenant, $product, [
        'name' => $product->name, 'brand' => $product->brand, 'description' => $product->description, 'status' => $product->status->value,
        'variants' => [[
            'id' => $variant->id, 'name' => $variant->name, 'sku' => $variant->sku, 'barcode' => $variant->barcode,
            'base_price' => '200.00', 'currency' => $variant->currency, 'status' => $variant->status->value,
        ]],
    ]);
    VariantPriceChanged::dispatch($tenant->id, $variant->id);

    expect(SyncOperation::query()->where('operation', 'price_push')->count())->toBe(1);
    Queue::assertPushed(SyncVariantPriceJob::class, 1);
});

it('pushes summed available stock to the mapped WooCommerce variation', function () {
    Http::fake(['*' => Http::response(['id' => 77])]);
    registerFakeSafeWooConnector();
    ['tenant' => $tenant, 'account' => $account, 'variant' => $variant, 'listing' => $listing] = syncedWooListing();
    $active = Warehouse::factory()->for($tenant)->create(['is_active' => true]);
    $inactive = Warehouse::factory()->for($tenant)->create(['is_active' => false]);
    InventoryItem::query()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $active->id, 'product_variant_id' => $variant->id, 'quantity' => 12, 'reserved_quantity' => 3]);
    InventoryItem::query()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $inactive->id, 'product_variant_id' => $variant->id, 'quantity' => 50, 'reserved_quantity' => 0]);
    $sync = $account->syncOperations()->create(['tenant_id' => $tenant->id, 'operation' => 'inventory_push', 'entity_type' => $listing->getMorphClass(), 'entity_id' => $listing->id]);

    (new SyncVariantInventoryJob($sync->id))->handle();

    expect($sync->fresh()->status)->toBe(SyncOperationStatus::Succeeded);
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request->url() === 'https://shop.example.com/wp-json/wc/v3/products/55/variations/77' && $request['manage_stock'] === true && $request['stock_quantity'] === 9);
});

it('pushes exact decimal prices and rejects currency mismatches', function () {
    Http::fake(['*' => Http::response(['id' => 77])]);
    registerFakeSafeWooConnector();
    ['tenant' => $tenant, 'account' => $account, 'variant' => $variant, 'listing' => $listing] = syncedWooListing();
    $sync = $account->syncOperations()->create(['tenant_id' => $tenant->id, 'operation' => 'price_push', 'entity_type' => $listing->getMorphClass(), 'entity_id' => $listing->id]);

    (new SyncVariantPriceJob($sync->id))->handle();
    Http::assertSent(fn (Request $request) => $request['regular_price'] === '125.50');

    $variant->update(['currency' => 'USD']);
    $failed = $account->syncOperations()->create(['tenant_id' => $tenant->id, 'operation' => 'price_push', 'entity_type' => $listing->getMorphClass(), 'entity_id' => $listing->id]);
    (new SyncVariantPriceJob($failed->id))->handle();
    $failed->refresh();
    expect($failed->status)->toBe(SyncOperationStatus::Failed)->and($failed->error_category)->toBe(SyncErrorCategory::Validation);
});

it('allows authorized manual retry without reusing the failed operation', function () {
    Queue::fake();
    ['tenant' => $tenant, 'account' => $account, 'listing' => $listing] = syncedWooListing();
    $operator = tenantMember($tenant, TenantRole::Operator);
    $viewer = tenantMember($tenant, TenantRole::Viewer);
    $failed = $account->syncOperations()->create(['tenant_id' => $tenant->id, 'operation' => 'inventory_push', 'entity_type' => $listing->getMorphClass(), 'entity_id' => $listing->id, 'status' => SyncOperationStatus::Failed, 'error_category' => SyncErrorCategory::Network]);

    $this->actingAs($viewer)->post("/sync/{$failed->id}/retry")->assertForbidden();
    $this->actingAs($operator)->post("/sync/{$failed->id}/retry")->assertRedirect('/sync');

    $retry = SyncOperation::query()->whereKeyNot($failed->id)->sole();
    expect($retry->status)->toBe(SyncOperationStatus::Pending)->and($retry->context)->toBe(['retry_of' => $failed->id]);
    Queue::assertPushed(SyncVariantInventoryJob::class, 1);
});
