<?php

declare(strict_types=1);

use App\Domain\Channels\Actions\ImportWooCommerceProduct;
use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Sync\Actions\CreateSyncOperation;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Integrations\WooCommerce\WooCommerceCatalogImporter;
use App\Integrations\WooCommerce\WooCommerceClient;
use App\Integrations\WooCommerce\WooCommerceUrlGuard;
use App\Jobs\ImportWooCommerceProductsPageJob;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function simpleWooProduct(array $overrides = []): array
{
    return [
        'id' => 101,
        'name' => 'Imported Shoe',
        'type' => 'simple',
        'status' => 'publish',
        'description' => '<p>Safe description</p>',
        'sku' => 'WC-SHOE-1',
        'global_unique_id' => '8690000000101',
        'price' => '1299.90',
        'brands' => [['name' => 'Acme']],
        ...$overrides,
    ];
}

function catalogImporter(): WooCommerceCatalogImporter
{
    $guard = Mockery::mock(WooCommerceUrlGuard::class);
    $guard->shouldReceive('assertSafe')->andReturnNull();

    return new WooCommerceCatalogImporter(new WooCommerceClient($guard), app(ImportWooCommerceProduct::class));
}

it('imports a simple product idempotently and updates its canonical mapping', function () {
    $account = wooAccount(Tenant::factory()->create(), ['status' => ChannelAccountStatus::Active, 'settings' => ['currency' => 'TRY'], 'credentials_encrypted' => wooCredentialsPayload(['store_url' => 'https://shop.example.com'])]);
    Http::fake([
        '*/products?*' => Http::sequence()
            ->push([simpleWooProduct()], 200, ['X-WP-TotalPages' => '1'])
            ->push([simpleWooProduct(['name' => 'Updated Shoe', 'price' => '1499.00'])], 200, ['X-WP-TotalPages' => '1']),
    ]);
    $importer = catalogImporter();

    expect($importer->importPage($account, 1)->successful)->toBeTrue();
    expect($importer->importPage($account->fresh(), 1)->successful)->toBeTrue();

    expect(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(ChannelListing::query()->count())->toBe(1)
        ->and(Product::query()->sole()->name)->toBe('Updated Shoe')
        ->and(ProductVariant::query()->sole()->base_price_amount)->toBe(149900)
        ->and(ChannelListing::query()->sole()->external_product_id)->toBe('101');
});

it('normalizes variable products and creates one mapping per sellable variation', function () {
    $account = wooAccount(Tenant::factory()->create(), ['settings' => ['currency' => 'EUR']]);
    $product = simpleWooProduct(['id' => 202, 'name' => 'Variable Shirt', 'type' => 'variable']);
    $variations = [
        ['id' => 301, 'status' => 'publish', 'sku' => 'SHIRT-BLK-M', 'global_unique_id' => '111', 'price' => '20.50', 'attributes' => [['name' => 'Color', 'option' => 'Black'], ['name' => 'Size', 'option' => 'M']]],
        ['id' => 302, 'status' => 'publish', 'sku' => 'SHIRT-WHT-L', 'price' => '21.00', 'attributes' => [['name' => 'Color', 'option' => 'White'], ['name' => 'Size', 'option' => 'L']]],
    ];

    app(ImportWooCommerceProduct::class)->execute($account, $product, $variations);

    expect(Product::query()->count())->toBe(1)->and(ProductVariant::query()->count())->toBe(2)->and(ChannelListing::query()->count())->toBe(2)
        ->and(ProductVariant::query()->orderBy('sku')->first()->name)->toContain('Color: Black')
        ->and(ChannelListing::query()->pluck('external_variant_id')->sort()->values()->all())->toBe(['301', '302'])
        ->and(ProductVariant::query()->pluck('currency')->unique()->all())->toBe(['EUR']);
});

it('reads and stores the WooCommerce currency before the first import page', function () {
    $account = wooAccount(Tenant::factory()->create(), ['settings' => [], 'credentials_encrypted' => wooCredentialsPayload(['store_url' => 'https://shop.example.com'])]);
    Http::fake([
        '*/data/currencies/current' => Http::response(['code' => 'USD']),
        '*/products?*' => Http::response([simpleWooProduct()], 200, ['X-WP-TotalPages' => '1']),
    ]);

    $result = catalogImporter()->importPage($account, 1);

    expect($result->successful)->toBeTrue()->and($account->fresh()->settings['currency'])->toBe('USD')->and(ProductVariant::query()->sole()->currency)->toBe('USD');
});

it('fans out independent jobs for remaining remote pages and records progress', function () {
    Queue::fake();
    $account = wooAccount(Tenant::factory()->create(), ['status' => ChannelAccountStatus::Active, 'settings' => ['currency' => 'TRY'], 'credentials_encrypted' => wooCredentialsPayload(['store_url' => 'https://shop.example.com'])]);
    $operation = app(CreateSyncOperation::class)->execute($account, 'product_import', context: ['total_pages' => 1, 'processed_pages' => [], 'imported' => 0, 'failed' => 0, 'skipped' => 0]);
    Http::fake(['*/products?*' => Http::response([simpleWooProduct()], 200, ['X-WP-TotalPages' => '3'])]);

    (new ImportWooCommerceProductsPageJob($operation->id))->handle(catalogImporter());

    $operation->refresh();
    expect($operation->status)->toBe(SyncOperationStatus::Running)->and($operation->context['processed_pages'])->toBe([1])->and($operation->context['total_pages'])->toBe(3);
    Queue::assertPushed(ImportWooCommerceProductsPageJob::class, 2);
    Queue::assertPushed(fn (ImportWooCommerceProductsPageJob $job) => $job->page === 2);
    Queue::assertPushed(fn (ImportWooCommerceProductsPageJob $job) => $job->page === 3);
});

it('allows operators to queue imports only for active own-tenant WooCommerce accounts', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $operator = tenantMember($tenant, TenantRole::Operator);
    $viewer = tenantMember($tenant, TenantRole::Viewer);
    $active = wooAccount($tenant, ['status' => ChannelAccountStatus::Active, 'credentials_encrypted' => wooCredentialsPayload()]);
    $pending = wooAccount($tenant, ['name' => 'Pending store', 'status' => ChannelAccountStatus::Pending, 'credentials_encrypted' => wooCredentialsPayload()]);

    $this->actingAs($operator)->post("/channels/accounts/{$active->id}/woocommerce/import-products")->assertRedirect();
    $this->actingAs($viewer)->post("/channels/accounts/{$active->id}/woocommerce/import-products")->assertForbidden();
    $this->actingAs($operator)->post("/channels/accounts/{$pending->id}/woocommerce/import-products")->assertStatus(422);
    expect($active->syncOperations()->where('operation', 'product_import')->count())->toBe(1);
});
