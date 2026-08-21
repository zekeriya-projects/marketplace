<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Jobs\PublishTrendyolListingJob;
use App\Models\Category;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncOperation;
use App\Models\Tenant;
use App\Models\TrendyolListingTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function trendyolBulkUser(Tenant $tenant, TenantRole $role = TenantRole::Operator): User
{
    $user = User::factory()->create(['active_tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id, ['role' => $role->value]);

    return $user;
}

function trendyolBulkAccount(Tenant $tenant): ChannelAccount
{
    return ChannelAccount::factory()->for($tenant)->create([
        'channel_id' => Channel::query()->where('code', 'trendyol')->firstOrFail()->id,
        'status' => ChannelAccountStatus::Active,
    ]);
}

function trendyolBulkTemplate(Tenant $tenant, ?Category $category = null): TrendyolListingTemplate
{
    return TrendyolListingTemplate::query()->create([
        'tenant_id' => $tenant->id, 'category_id' => $category?->id, 'name' => 'Ayakkabı şablonu',
        'trendyol_category_id' => 456, 'trendyol_brand_id' => 123, 'image_url' => 'https://cdn.example.com/default.jpg',
        'vat_rate' => 20, 'dimensional_weight' => 1, 'origin' => 'TR',
        'attributes' => [['attributeId' => 9, 'attributeValueId' => 10]], 'required_attribute_ids' => [9],
    ]);
}

it('queues ready products with a tenant template while reporting blocked products independently', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $category = $tenant->categories()->create(['name' => 'Ayakkabı', 'slug' => 'ayakkabi', 'status' => 'active']);
    $account = trendyolBulkAccount($tenant);
    $template = trendyolBulkTemplate($tenant, $category);
    $ready = Product::factory()->for($tenant)->create(['category_id' => $category->id]);
    ProductVariant::factory()->for($ready)->create(['tenant_id' => $tenant->id, 'sku' => 'READY-1', 'barcode' => '86900001', 'currency' => 'TRY']);
    $blocked = Product::factory()->for($tenant)->create(['category_id' => $category->id]);
    ProductVariant::factory()->for($blocked)->create(['tenant_id' => $tenant->id, 'sku' => 'BLOCKED-1', 'barcode' => null, 'currency' => 'TRY']);

    $payload = ['product_ids' => [$ready->id, $blocked->id], 'channel_account_ids' => [$account->id], 'trendyol_template_id' => $template->id, 'select_all' => false];
    $response = $this->actingAs(trendyolBulkUser($tenant))->post('/products/bulk-publish', $payload)->assertRedirect();
    $response->assertSessionHas('bulk_publish_result', fn (array $result) => $result['queued'] === 1 && $result['blocked'] === 1);

    expect(ChannelListing::query()->count())->toBe(1)->and(SyncOperation::query()->count())->toBe(1);
    Queue::assertPushed(PublishTrendyolListingJob::class, 1);

    $this->post('/products/bulk-publish', $payload)->assertRedirect()->assertSessionHas('bulk_publish_result', fn (array $result) => $result['already_linked'] === 1 && $result['blocked'] === 1);
    expect(ChannelListing::query()->count())->toBe(1)->and(SyncOperation::query()->count())->toBe(1);
    Queue::assertPushed(PublishTrendyolListingJob::class, 1);
});

it('creates reusable templates from the three-step publish flow and enforces tenant authorization', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $category = $tenant->categories()->create(['name' => 'Ayakkabı', 'slug' => 'ayakkabi', 'status' => 'active']);
    $account = trendyolBulkAccount($tenant);
    $product = Product::factory()->for($tenant)->create(['category_id' => $category->id]);
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id, 'sku' => 'SKU-1', 'barcode' => '8691', 'currency' => 'TRY']);
    $payload = ['variant_id' => $variant->id, 'brand_id' => 123, 'category_id' => 456, 'image_url' => 'https://cdn.example.com/p.jpg', 'vat_rate' => 20, 'dimensional_weight' => 1, 'origin' => 'tr', 'attributes' => [['attributeId' => 9, 'attributeValueId' => 10]], 'required_attribute_ids' => [9], 'template_name' => 'Yeni şablon', 'central_category_id' => $category->id];

    $this->actingAs(trendyolBulkUser($tenant))->post("/channels/accounts/{$account->id}/trendyol/listings", $payload)->assertRedirect();
    $template = TrendyolListingTemplate::query()->sole();
    expect($template->tenant_id)->toBe($tenant->id)->and($template->origin)->toBe('TR')->and($template->category_id)->toBe($category->id);

    $other = Tenant::factory()->create();
    $foreign = trendyolBulkTemplate($other);
    $this->post('/products/bulk-publish', ['product_ids' => [$product->id], 'channel_account_ids' => [$account->id], 'trendyol_template_id' => $foreign->id, 'select_all' => false])->assertSessionHasErrors('trendyol_template_id');
    $this->actingAs(trendyolBulkUser($tenant, TenantRole::Viewer))->delete("/trendyol-listing-templates/{$template->id}")->assertForbidden();
});
