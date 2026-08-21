<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantRole;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function productPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Trail Shoe',
        'brand' => 'Acme',
        'description' => 'A central catalog product.',
        'status' => 'active',
        'variants' => [[
            'name' => 'Black / 42',
            'sku' => 'SHOE-BLK-42',
            'barcode' => '8690000000001',
            'base_price' => '1299.90',
            'currency' => 'TRY',
            'status' => 'active',
        ]],
    ], $overrides);
}

function catalogProductMember(Tenant $tenant, TenantRole $role = TenantRole::Owner): User
{
    $user = User::factory()->create(['active_tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id, ['role' => $role->value]);

    return $user;
}

it('creates a tenant scoped product with an exact minor unit price', function () {
    $tenant = Tenant::factory()->create();
    $user = catalogProductMember($tenant, TenantRole::Operator);

    $response = $this->actingAs($user)->post('/products', productPayload());

    $product = Product::query()->sole();
    $variant = $product->variants()->sole();
    $response->assertRedirect("/products/{$product->id}");
    expect($product->tenant_id)->toBe($tenant->id)
        ->and($variant->tenant_id)->toBe($tenant->id)
        ->and($variant->base_price_amount)->toBe(129990);
});

it('exposes active tenant templates on the product type selection screen', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = catalogProductMember($tenant);
    $tenant->variantTemplates()->create(['name' => 'Renk ve Beden', 'options' => [['name' => 'Renk', 'values' => ['Siyah']]], 'status' => 'active']);
    $tenant->variantTemplates()->create(['name' => 'Arşiv', 'options' => [['name' => 'Beden', 'values' => ['M']]], 'status' => 'archived']);
    $other->variantTemplates()->create(['name' => 'Gizli', 'options' => [['name' => 'Renk', 'values' => ['Mavi']]], 'status' => 'active']);

    $this->actingAs($user)->get('/products/create')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Products/Create')
        ->has('variantTemplates', 1)
        ->where('variantTemplates.0.name', 'Renk ve Beden'));
});

it('requires an active own tenant template for variable products', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = catalogProductMember($tenant, TenantRole::Operator);
    $foreignTemplate = $other->variantTemplates()->create(['name' => 'Yabancı', 'options' => [['name' => 'Renk', 'values' => ['Siyah']]], 'status' => 'active']);

    $payload = productPayload(['product_type' => 'variable', 'variants' => [['variant_template_id' => $foreignTemplate->id]]]);
    $this->actingAs($user)->post('/products', $payload)->assertSessionHasErrors('variants.0.variant_template_id');
    expect(Product::query()->count())->toBe(0);

    $template = $tenant->variantTemplates()->create(['name' => 'Renk', 'options' => [['name' => 'Renk', 'values' => ['Siyah']]], 'status' => 'active']);
    $payload = productPayload(['product_type' => 'variable', 'variants' => [['variant_template_id' => $template->id, 'option_values' => ['Renk' => 'Siyah']]]]);
    $this->actingAs($user)->post('/products', $payload)->assertRedirect();
    expect(ProductVariant::query()->sole()->variant_template_id)->toBe($template->id)
        ->and(ProductVariant::query()->sole()->option_values)->toBe(['Renk' => 'Siyah']);
});

it('stores detailed commercial fields and tenant scoped product images', function () {
    Storage::fake('public');
    $tenant = Tenant::factory()->create();
    $user = catalogProductMember($tenant, TenantRole::Operator);
    $payload = productPayload([
        'short_name' => 'Kısa ürün', 'invoice_name' => 'Fatura ürün adı', 'custom_code_1' => 'OZEL-1', 'custom_code_2' => 'OZEL-2',
        'compare_at_price' => '1499.90', 'purchase_price' => '800.25', 'desi' => '3.50', 'desi_2' => '4.25', 'vat_rate' => 20,
        'excise_tax_rate' => '5.25', 'communication_tax_rate' => '1.50', 'disable_external_sync' => true,
        'vat_exemption_code' => 'IST-01', 'expiration_date' => '2027-08-12', 'images' => [UploadedFile::fake()->image('urun.jpg')],
    ]);

    $this->actingAs($user)->post('/products', $payload)->assertRedirect();

    $product = Product::query()->sole();
    expect($product->compare_at_price_amount)->toBe(149990)->and($product->purchase_price_amount)->toBe(80025)
        ->and($product->disable_external_sync)->toBeTrue()->and($product->images()->count())->toBe(1)
        ->and($product->images()->sole()->tenant_id)->toBe($tenant->id);
    Storage::disk('public')->assertExists($product->images()->sole()->path);
});

it('lists only products belonging to the active tenant', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $user = catalogProductMember($tenant);
    Product::factory()->for($tenant)->create(['name' => 'Visible product']);
    Product::factory()->for($otherTenant)->create(['name' => 'Hidden product']);

    $this->actingAs($user)->get('/products')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Products/Index')
        ->has('products.data', 1)
        ->where('products.data.0.name', 'Visible product'));
});

it('searches products by variant SKU within the active tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = catalogProductMember($tenant);
    $match = Product::factory()->for($tenant)->create(['name' => 'Matched']);
    ProductVariant::factory()->for($match)->create(['tenant_id' => $tenant->id, 'sku' => 'FIND-ME']);
    Product::factory()->for($tenant)->create(['name' => 'Not matched']);

    $this->actingAs($user)->get('/products?search=FIND-ME')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)
        ->where('products.data.0.id', $match->id));
});

it('paginates the catalog on the server', function () {
    $tenant = Tenant::factory()->create();
    $user = catalogProductMember($tenant);
    Product::factory()->for($tenant)->count(16)->create();

    $this->actingAs($user)->get('/products')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 15)
        ->where('products.total', 16)
        ->where('products.current_page', 1)
        ->where('products.last_page', 2));
});

it('prevents cross tenant product reads and updates', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $user = catalogProductMember($tenant);
    $product = Product::factory()->for($otherTenant)->create(['name' => 'Protected']);
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $otherTenant->id]);

    $this->actingAs($user)->get("/products/{$product->id}")->assertForbidden();
    $this->actingAs($user)->put("/products/{$product->id}", productPayload([
        'name' => 'Compromised',
        'variants' => [['id' => $variant->id]],
    ]))->assertForbidden();
    expect($product->fresh()->name)->toBe('Protected');
});

it('allows viewers to read but not create or update catalog products', function () {
    $tenant = Tenant::factory()->create();
    $user = catalogProductMember($tenant, TenantRole::Viewer);
    $product = Product::factory()->for($tenant)->create();

    $this->actingAs($user)->get("/products/{$product->id}")->assertOk();
    $this->actingAs($user)->post('/products', productPayload())->assertForbidden();
    $this->actingAs($user)->get("/products/{$product->id}/edit")->assertForbidden();
});

it('enforces SKU uniqueness inside a tenant but permits the same SKU in another tenant', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $user = catalogProductMember($tenant);
    $otherUser = catalogProductMember($otherTenant);

    $this->actingAs($user)->post('/products', productPayload())->assertRedirect();
    $this->actingAs($user)->post('/products', productPayload(['name' => 'Duplicate']))
        ->assertSessionHasErrors('variants.0.sku');
    $this->actingAs($otherUser)->post('/products', productPayload(['name' => 'Other tenant']))
        ->assertRedirect();

    expect(ProductVariant::query()->where('sku', 'SHOE-BLK-42')->count())->toBe(2);
});

it('rejects attaching a variant from another product during update', function () {
    $tenant = Tenant::factory()->create();
    $user = catalogProductMember($tenant);
    $product = Product::factory()->for($tenant)->create();
    $otherProduct = Product::factory()->for($tenant)->create();
    $otherVariant = ProductVariant::factory()->for($otherProduct)->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->put("/products/{$product->id}", productPayload([
        'variants' => [[
            'id' => $otherVariant->id,
            'sku' => 'OTHER-SKU',
        ]],
    ]))->assertSessionHasErrors('variants');
});
