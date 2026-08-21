<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\CatalogStatus;
use App\Domain\Inventory\Actions\AdjustInventory;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VariantDefinition;
use App\Models\VariantTemplate;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DemoStoreSeeder extends Seeder
{
    public function run(): void
    {
        [$user, $tenant, $warehouse, $variants] = DB::transaction(function (): array {
            $tenant = Tenant::query()->updateOrCreate(
                ['slug' => 'demo-magaza'],
                ['name' => 'Demo Mağaza', 'status' => TenantStatus::Active],
            );

            $user = User::query()->updateOrCreate(
                ['email' => 'demo@marketplace.test'],
                ['name' => 'Demo Kullanıcı', 'password' => 'password', 'email_verified_at' => now(), 'active_tenant_id' => $tenant->id],
            );
            $tenant->users()->syncWithoutDetaching([$user->id => ['role' => TenantRole::Owner->value]]);

            $shoes = Category::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'ayakkabi'],
                ['name' => 'Ayakkabı', 'description' => 'Demo ayakkabı kategorisi', 'status' => CatalogStatus::Active],
            );
            $sportsShoes = Category::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'spor-ayakkabi'],
                ['parent_id' => $shoes->id, 'name' => 'Spor Ayakkabı', 'description' => 'Günlük ve performans spor ayakkabıları', 'status' => CatalogStatus::Active],
            );
            $accessories = Category::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'aksesuar'],
                ['name' => 'Aksesuar', 'description' => 'Demo aksesuar kategorisi', 'status' => CatalogStatus::Active],
            );

            $northstar = Brand::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'northstar'],
                ['name' => 'Northstar', 'description' => 'Demo spor ürünleri markası', 'status' => CatalogStatus::Active],
            );
            $urban = Brand::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'urban-loop'],
                ['name' => 'Urban Loop', 'description' => 'Demo günlük aksesuar markası', 'status' => CatalogStatus::Active],
            );

            $color = VariantDefinition::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Renk'],
                ['values' => ['Siyah', 'Beyaz'], 'status' => CatalogStatus::Active],
            );
            $size = VariantDefinition::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Beden'],
                ['values' => ['40', '41', '42'], 'status' => CatalogStatus::Active],
            );
            $template = VariantTemplate::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Renk ve Beden'],
                [
                    'description' => 'Ayakkabı ürünleri için demo varyant şablonu',
                    'options' => [
                        ['definition_id' => $color->id, 'name' => $color->name, 'values' => $color->values],
                        ['definition_id' => $size->id, 'name' => $size->name, 'values' => $size->values],
                    ],
                    'status' => CatalogStatus::Active,
                ],
            );

            $runner = Product::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'custom_code_1' => 'DEMO-RUNNER'],
                [
                    'category_id' => $sportsShoes->id, 'brand_id' => $northstar->id,
                    'name' => 'Northstar Runner Pro', 'short_name' => 'Runner Pro',
                    'invoice_name' => 'Northstar Runner Pro Spor Ayakkabı',
                    'brand' => $northstar->name, 'description' => 'Hafif tabanlı günlük koşu ayakkabısı.',
                    'compare_at_price_amount' => 249900, 'purchase_price_amount' => 105000,
                    'desi' => 2.50, 'vat_rate' => 20, 'status' => CatalogStatus::Active,
                ],
            );
            $bag = Product::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'custom_code_1' => 'DEMO-BAG'],
                [
                    'category_id' => $accessories->id, 'brand_id' => $urban->id,
                    'name' => 'Urban Loop Günlük Çanta', 'short_name' => 'Günlük Çanta',
                    'invoice_name' => 'Urban Loop Günlük Çanta', 'brand' => $urban->name,
                    'description' => 'Ayarlanabilir askılı şehir çantası.',
                    'compare_at_price_amount' => 129900, 'purchase_price_amount' => 48000,
                    'desi' => 1.20, 'vat_rate' => 20, 'status' => CatalogStatus::Active,
                ],
            );

            $variants = [
                ProductVariant::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'sku' => 'NS-RUN-BLK-40'],
                    ['product_id' => $runner->id, 'variant_template_id' => $template->id, 'option_values' => ['Renk' => 'Siyah', 'Beden' => '40'], 'name' => 'Siyah / 40', 'barcode' => '8690000000011', 'base_price_amount' => 199900, 'currency' => 'TRY', 'status' => CatalogStatus::Active],
                ),
                ProductVariant::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'sku' => 'NS-RUN-WHT-41'],
                    ['product_id' => $runner->id, 'variant_template_id' => $template->id, 'option_values' => ['Renk' => 'Beyaz', 'Beden' => '41'], 'name' => 'Beyaz / 41', 'barcode' => '8690000000028', 'base_price_amount' => 199900, 'currency' => 'TRY', 'status' => CatalogStatus::Active],
                ),
                ProductVariant::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'sku' => 'UL-BAG-BLK'],
                    ['product_id' => $bag->id, 'name' => 'Siyah', 'barcode' => '8690000000035', 'base_price_amount' => 99900, 'currency' => 'TRY', 'status' => CatalogStatus::Active],
                ),
            ];

            $warehouse = Warehouse::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => 'MERKEZ'],
                ['name' => 'Merkez Depo', 'is_default' => true, 'is_active' => true],
            );

            return [$user, $tenant, $warehouse, $variants];
        });

        $targets = [25, 14, 8];
        $adjustInventory = app(AdjustInventory::class);
        foreach ($variants as $index => $variant) {
            $current = InventoryItem::query()
                ->where('tenant_id', $tenant->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('product_variant_id', $variant->id)
                ->value('quantity') ?? 0;
            $delta = $targets[$index] - (int) $current;
            if ($delta !== 0) {
                $adjustInventory->execute($tenant, $warehouse, $variant, $delta, 'Demo başlangıç stoğu', $user);
            }
        }
    }
}
