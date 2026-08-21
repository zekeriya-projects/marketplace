<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantRole;
use App\Integrations\WooCommerce\WooCommerceUrlGuard;
use App\Jobs\PublishWooCommerceProductJob;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\ChannelReferenceMapping;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VariantDefinition;
use App\Models\VariantTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

function catalogMember(Tenant $tenant, TenantRole $role = TenantRole::Owner): User
{
    $user = User::factory()->create(['active_tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id, ['role' => $role->value]);

    return $user;
}

it('manages tenant scoped categories and brands', function () {
    $tenant = Tenant::factory()->create();
    $user = catalogMember($tenant, TenantRole::Operator);
    $this->actingAs($user)->post('/categories', ['name' => 'Ayakkabı', 'slug' => 'ayakkabi', 'parent_id' => null, 'description' => null, 'status' => 'active'])->assertRedirect();
    $this->actingAs($user)->post('/brands', ['name' => 'Merkez', 'slug' => 'merkez', 'description' => null, 'status' => 'active'])->assertRedirect();

    expect(Category::query()->sole()->tenant_id)->toBe($tenant->id)->and(Brand::query()->sole()->tenant_id)->toBe($tenant->id);
    $this->actingAs($user)->get('/categories')->assertOk()->assertInertia(fn (Assert $page) => $page->where('categories.data.0.name', 'Ayakkabı'));
    $this->actingAs($user)->get('/brands')->assertOk()->assertInertia(fn (Assert $page) => $page->where('brands.data.0.name', 'Merkez'));
});

it('prevents viewer writes and cross tenant category mutation', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $viewer = catalogMember($tenant, TenantRole::Viewer);
    $operator = catalogMember($tenant, TenantRole::Operator);
    $category = $other->categories()->create(['name' => 'Gizli', 'slug' => 'gizli', 'status' => 'active']);

    $this->actingAs($viewer)->post('/categories', ['name' => 'Yeni', 'slug' => 'yeni', 'status' => 'active'])->assertForbidden();
    $this->actingAs($operator)->put("/categories/{$category->id}", ['name' => 'Değiştir', 'slug' => 'degistir', 'status' => 'active'])->assertForbidden();
});

it('maps brands and categories to an active tenant channel account', function () {
    $tenant = Tenant::factory()->create();
    $user = catalogMember($tenant, TenantRole::Operator);
    $brand = $tenant->brands()->create(['name' => 'Merkez Marka', 'slug' => 'merkez-marka', 'status' => 'active']);
    $account = ChannelAccount::factory()->for($tenant)->create([
        'channel_id' => Channel::query()->where('code', 'trendyol')->firstOrFail()->id,
        'status' => 'active',
    ]);

    $this->actingAs($user)->post('/channel-reference-mappings', [
        'channel_account_id' => $account->id,
        'reference_type' => 'brand',
        'reference_id' => $brand->id,
        'external_id' => '42',
        'external_name' => 'Pazaryeri Markası',
    ])->assertRedirect();

    expect(ChannelReferenceMapping::query()->sole())
        ->tenant_id->toBe($tenant->id)
        ->external_id->toBe('42');
});

it('rejects cross tenant reference mappings', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = catalogMember($tenant, TenantRole::Operator);
    $brand = $other->brands()->create(['name' => 'Gizli Marka', 'slug' => 'gizli-marka', 'status' => 'active']);
    $account = ChannelAccount::factory()->for($tenant)->create([
        'channel_id' => Channel::query()->where('code', 'trendyol')->firstOrFail()->id,
    ]);

    $this->actingAs($user)->post('/channel-reference-mappings', [
        'channel_account_id' => $account->id,
        'reference_type' => 'brand',
        'reference_id' => $brand->id,
        'external_id' => '99',
        'external_name' => 'Başka Tenant',
    ])->assertNotFound();

    expect(ChannelReferenceMapping::query()->count())->toBe(0);
});

it('manually maps a tenant product variant to an existing marketplace product', function () {
    $tenant = Tenant::factory()->create();
    $user = catalogMember($tenant, TenantRole::Operator);
    $account = ChannelAccount::factory()->for($tenant)->create([
        'channel_id' => Channel::query()->where('code', 'woocommerce')->firstOrFail()->id,
        'status' => 'active',
    ]);
    $product = Product::factory()->for($tenant)->create();
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id, 'sku' => 'MAP-1']);

    $this->actingAs($user)->post('/channel-listing-mappings', [
        'channel_account_id' => $account->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'external_product_id' => 'woo-55',
        'external_variant_id' => '',
    ])->assertRedirect();

    expect(ChannelListing::query()->sole())
        ->tenant_id->toBe($tenant->id)
        ->external_product_id->toBe('woo-55');
});

it('lists WooCommerce products without exposing marketplace ids as customer input', function () {
    $tenant = Tenant::factory()->create();
    $user = catalogMember($tenant, TenantRole::Operator);
    $account = ChannelAccount::factory()->for($tenant)->create([
        'channel_id' => Channel::query()->where('code', 'woocommerce')->firstOrFail()->id,
        'status' => 'active',
        'credentials_encrypted' => [
            'store_url' => 'https://shop.example.com',
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ],
    ]);
    Http::fake([
        'https://shop.example.com/wp-json/wc/v3/products*' => Http::response([[
            'id' => 55,
            'name' => 'Uzaktaki ürün',
            'type' => 'simple',
            'status' => 'publish',
            'sku' => 'REMOTE-55',
            'global_unique_id' => '8690000000055',
            'images' => [['src' => 'https://cdn.example.com/product.jpg']],
        ]]),
    ]);
    $guard = Mockery::mock(WooCommerceUrlGuard::class);
    $guard->shouldReceive('assertSafe')->once()->andReturnNull();
    $this->app->instance(WooCommerceUrlGuard::class, $guard);

    $this->actingAs($user)
        ->getJson("/channels/accounts/{$account->id}/marketplace-products?search=REMOTE-55")
        ->assertOk()
        ->assertJsonPath('products.0.external_product_id', '55')
        ->assertJsonPath('products.0.name', 'Uzaktaki ürün')
        ->assertJsonPath('products.0.selectable', true)
        ->assertJsonPath('products.0.already_mapped', false);

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://shop.example.com/wp-json/wc/v3/products?')
        && $request['search'] === 'REMOTE-55'
        && $request['page'] === 1
        && $request['per_page'] === 20);
});

it('does not allow another tenant to browse marketplace products', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = catalogMember($tenant, TenantRole::Operator);
    $account = ChannelAccount::factory()->for($other)->create([
        'channel_id' => Channel::query()->where('code', 'woocommerce')->firstOrFail()->id,
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->getJson("/channels/accounts/{$account->id}/marketplace-products")
        ->assertForbidden();
});

it('queues selected products for selected WooCommerce accounts in bulk', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $user = catalogMember($tenant, TenantRole::Operator);
    $account = ChannelAccount::factory()->for($tenant)->create([
        'channel_id' => Channel::query()->where('code', 'woocommerce')->firstOrFail()->id,
        'status' => 'active',
    ]);
    $products = Product::factory()->for($tenant)->count(2)->create();
    $products->each(fn (Product $product) => ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id]));

    $this->actingAs($user)->post('/products/bulk-publish', [
        'product_ids' => $products->pluck('id')->all(),
        'channel_account_ids' => [$account->id],
        'select_all' => false,
    ])->assertRedirect()->assertSessionHas('success', '2 ürün yayını kuyruğa alındı.');

    expect(ChannelListing::query()->count())->toBe(2);
    Queue::assertPushed(PublishWooCommerceProductJob::class, 2);
});

it('rejects cross tenant ids in bulk publishing', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = catalogMember($tenant, TenantRole::Operator);
    $account = ChannelAccount::factory()->for($tenant)->create([
        'channel_id' => Channel::query()->where('code', 'woocommerce')->firstOrFail()->id,
        'status' => 'active',
    ]);
    $foreignProduct = Product::factory()->for($other)->create();

    $this->actingAs($user)->post('/products/bulk-publish', [
        'product_ids' => [$foreignProduct->id],
        'channel_account_ids' => [$account->id],
        'select_all' => false,
    ])->assertNotFound();

    Queue::assertNothingPushed();
});

it('enforces category and brand slugs per tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = catalogMember($tenant);
    $tenant->categories()->create(['name' => 'Bir', 'slug' => 'ortak', 'status' => 'active']);
    $tenant->brands()->create(['name' => 'Bir', 'slug' => 'ortak', 'status' => 'active']);

    $this->actingAs($user)->post('/categories', ['name' => 'İki', 'slug' => 'ortak', 'status' => 'active'])->assertSessionHasErrors('slug');
    $this->actingAs($user)->post('/brands', ['name' => 'İki', 'slug' => 'ortak', 'status' => 'active'])->assertSessionHasErrors('slug');
});

it('manages variants and builds templates only from existing tenant variants', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = catalogMember($tenant, TenantRole::Operator);

    $this->actingAs($user)->post('/variant-definitions', ['name' => 'Renk', 'values' => 'Siyah, Beyaz, Siyah', 'status' => 'active'])->assertRedirect();
    $definition = VariantDefinition::query()->sole();
    expect($definition->tenant_id)->toBe($tenant->id)->and($definition->values)->toBe(['Siyah', 'Beyaz']);

    $this->actingAs($user)->post('/variant-templates', ['name' => 'Giyim', 'description' => null, 'status' => 'active', 'definition_ids' => [$definition->id]])->assertRedirect();
    $template = VariantTemplate::query()->sole();
    expect($template->options)->toHaveCount(1)
        ->and($template->options[0]['definition_id'])->toBe($definition->id)
        ->and($template->options[0]['name'])->toBe('Renk')
        ->and($template->options[0]['values'])->toBe(['Siyah', 'Beyaz']);

    $foreign = $other->variantDefinitions()->create(['name' => 'Beden', 'values' => ['M'], 'status' => 'active']);
    $this->actingAs($user)->post('/variant-templates', ['name' => 'Geçersiz', 'status' => 'active', 'definition_ids' => [$foreign->id]])->assertSessionHasErrors('definition_ids.0');
    expect(VariantTemplate::query()->count())->toBe(1);
});

it('prevents viewers from adding variants', function () {
    $tenant = Tenant::factory()->create();
    $viewer = catalogMember($tenant, TenantRole::Viewer);

    $this->actingAs($viewer)->get('/variant-definitions')->assertOk();
    $this->actingAs($viewer)->post('/variant-definitions', ['name' => 'Renk', 'values' => 'Siyah', 'status' => 'active'])->assertForbidden();
});
