<?php

declare(strict_types=1);
use App\Domain\Channels\Actions\PublishTrendyolListing;
use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Integrations\Trendyol\TrendyolClient;
use App\Jobs\CheckTrendyolListingBatchJob;
use App\Jobs\PublishTrendyolListingJob;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function trendyolPublishPayload(ProductVariant $variant): array
{
    return ['variant_id' => $variant->id, 'brand_id' => 123, 'category_id' => 456, 'image_url' => 'https://cdn.example.com/product.jpg', 'vat_rate' => 20, 'dimensional_weight' => 1, 'origin' => 'TR', 'attributes' => []];
}

it('queues a tenant scoped Trendyol Product V2 listing', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $account = trendyolAccount($tenant, ['status' => ChannelAccountStatus::Active, 'credentials_encrypted' => trendyolCredentials()]);
    $product = Product::factory()->for($tenant)->create(['description' => 'Açıklama']);
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id, 'sku' => 'SKU-1', 'barcode' => '8690001', 'currency' => 'TRY']);
    $this->actingAs(tenantMember($tenant))->post("/channels/accounts/{$account->id}/trendyol/listings", trendyolPublishPayload($variant))->assertRedirect();
    $listing = ChannelListing::query()->sole();
    expect($listing->status)->toBe(ChannelListingStatus::Pending)->and($listing->metadata['category_id'])->toBe(456);
    Queue::assertPushed(PublishTrendyolListingJob::class, 1);
});

it('rejects cross tenant variants and incomplete marketplace identity', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $account = trendyolAccount($tenant, ['status' => ChannelAccountStatus::Active]);
    $product = Product::factory()->for($other)->create();
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $other->id]);
    $this->actingAs(tenantMember($tenant))->post("/channels/accounts/{$account->id}/trendyol/listings", trendyolPublishPayload($variant))->assertNotFound();
    expect(ChannelListing::query()->count())->toBe(0);
});

it('submits Product V2 and activates the listing after successful batch completion', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $account = trendyolAccount($tenant, ['status' => ChannelAccountStatus::Active, 'credentials_encrypted' => trendyolCredentials()]);
    $product = Product::factory()->for($tenant)->create(['description' => 'Ürün']);
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id, 'sku' => 'SKU-2', 'barcode' => '8690002', 'currency' => 'TRY']);
    $listing = app(PublishTrendyolListing::class)->execute($account, trendyolPublishPayload($variant));
    $operation = $account->syncOperations()->sole();
    Http::fake(['*/v2/products' => Http::response(['batchRequestId' => 'batch-1'])]);
    (new PublishTrendyolListingJob($operation->id))->handle();
    Queue::assertPushed(CheckTrendyolListingBatchJob::class);
    Http::fake(['*/batch-requests/batch-1' => Http::response(['status' => 'COMPLETED', 'failedItemCount' => 0, 'items' => [['status' => 'SUCCESS']]])]);
    (new CheckTrendyolListingBatchJob($operation->id))->handle(app(TrendyolClient::class));
    expect($listing->fresh()->status)->toBe(ChannelListingStatus::Active)->and($listing->fresh()->published_at)->not->toBeNull()->and($operation->fresh()->status)->toBe(SyncOperationStatus::Succeeded);
    Http::assertSent(fn ($request) => $request->method() === 'GET' || $request['items'][0]['barcode'] === '8690002');
});

it('records a safe rejection without persisting provider failure payloads', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $account = trendyolAccount($tenant, ['status' => ChannelAccountStatus::Active, 'credentials_encrypted' => trendyolCredentials()]);
    $product = Product::factory()->for($tenant)->create();
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id, 'sku' => 'SKU-3', 'barcode' => '8690003', 'currency' => 'TRY']);
    $listing = app(PublishTrendyolListing::class)->execute($account, trendyolPublishPayload($variant));
    $operation = $account->syncOperations()->sole();
    $listing->update(['metadata' => [...$listing->metadata, 'batch_request_id' => 'batch-x']]);
    Http::fake(['*' => Http::response(['status' => 'COMPLETED', 'failedItemCount' => 1, 'items' => [['failureReasons' => ['secret raw reason']]]])]);
    (new CheckTrendyolListingBatchJob($operation->id))->handle(app(TrendyolClient::class));
    expect($listing->fresh()->status)->toBe(ChannelListingStatus::Rejected)->and($operation->fresh()->safe_error_message)->not->toContain('secret raw reason');
});

it('serves tenant-authorized Trendyol category brand and attribute lookups', function () {
    $tenant = Tenant::factory()->create();
    $account = trendyolAccount($tenant, ['status' => ChannelAccountStatus::Active, 'credentials_encrypted' => trendyolCredentials()]);
    $user = tenantMember($tenant);
    Http::fake([
        '*/product-categories' => Http::response(['categories' => [['id' => 1, 'name' => 'Giyim', 'subCategories' => [['id' => 2, 'name' => 'Elbise']]]]]),
        '*/brands/by-name*' => Http::response(['brands' => [['id' => 12, 'name' => 'Acme']]]),
        '*/categories/2/attributes' => Http::response(['categoryAttributes' => [['categoryAttribute' => ['id' => 9, 'name' => 'Renk'], 'required' => true, 'attributeValues' => [['id' => 10, 'name' => 'Siyah']]]]]),
    ]);

    $this->actingAs($user)->getJson("/channels/accounts/{$account->id}/trendyol/categories")
        ->assertOk()->assertJsonPath('categories.0.name', 'Giyim / Elbise');
    $this->actingAs($user)->getJson("/channels/accounts/{$account->id}/trendyol/brands?name=Acme")
        ->assertOk()->assertJsonPath('brands.0.id', 12);
    $this->actingAs($user)->getJson("/channels/accounts/{$account->id}/trendyol/categories/2/attributes")
        ->assertOk()->assertJsonPath('attributes.0.required', true);
});
