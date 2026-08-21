<?php

declare(strict_types=1);

use App\Domain\Catalog\Imports\CatalogImportProcessor;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Jobs\ProcessCatalogImportJob;
use App\Models\CatalogImport;
use App\Models\InventoryItem;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

function importMember(Tenant $tenant, TenantRole $role = TenantRole::Owner): User
{
    $user = User::factory()->create(['active_tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id, ['role' => $role->value]);

    return $user;
}

it('queues a tenant scoped catalog import without processing it in the request', function () {
    Storage::fake('local');
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $user = importMember($tenant, TenantRole::Operator);
    $file = UploadedFile::fake()->createWithContent('urunler.xml', '<products><product><product_name>Kalem</product_name><sku>KLM-1</sku></product></products>');

    $this->actingAs($user)->post('/imports', ['format' => 'xml', 'file' => $file])->assertRedirect();

    $import = CatalogImport::query()->sole();
    expect($import->tenant_id)->toBe($tenant->id)->and($import->status)->toBe('pending');
    Storage::disk('local')->assertExists($import->path);
    Queue::assertPushed(ProcessCatalogImportJob::class, fn ($job) => $job->importId === $import->id);
});

it('imports XML rows idempotently and records stock movement', function () {
    Storage::fake('local');
    $tenant = Tenant::factory()->create();
    $user = importMember($tenant);
    Warehouse::factory()->for($tenant)->create(['code' => 'MERKEZ', 'is_default' => true]);
    $xml = '<products><product><product_name>Tişört</product_name><category>Giyim</category><brand>Merkez</brand><variant_name>Siyah M</variant_name><sku>TS-1</sku><price>499.90</price><currency>TRY</currency><stock>12</stock><warehouse_code>MERKEZ</warehouse_code></product></products>';
    $path = Storage::disk('local')->put("catalog-imports/{$tenant->id}/urunler.xml", $xml);
    $import = $tenant->catalogImports()->create(['user_id' => $user->id, 'format' => 'xml', 'original_name' => 'urunler.xml', 'disk' => 'local', 'path' => "catalog-imports/{$tenant->id}/urunler.xml", 'status' => 'pending']);

    $processor = app(CatalogImportProcessor::class);
    $first = $processor->process($import);
    $second = $processor->process($import);

    expect($path)->toBeTrue()->and($first)->toMatchArray(['total' => 1, 'created' => 1, 'failed' => 0])->and($second)->toMatchArray(['total' => 1, 'updated' => 1, 'failed' => 0]);
    expect(ProductVariant::query()->count())->toBe(1)->and(ProductVariant::query()->sole()->base_price_amount)->toBe(49990)->and(InventoryItem::query()->sole()->quantity)->toBe(12);
});

it('provides a valid styled Excel catalog template', function () {
    $tenant = Tenant::factory()->create();
    $user = importMember($tenant, TenantRole::Viewer);

    $response = $this->actingAs($user)->get('/import-templates/catalog.xlsx')->assertOk();
    $temporary = tempnam(sys_get_temp_dir(), 'catalog-template-');
    file_put_contents($temporary, $response->streamedContent());
    $spreadsheet = IOFactory::load($temporary);

    expect($spreadsheet->getSheetByName('Ürünler')?->rangeToArray('A1:L1')[0])->toBe(['product_name', 'product_description', 'category', 'brand', 'variant_name', 'sku', 'barcode', 'price', 'currency', 'stock', 'warehouse_code', 'status'])
        ->and($spreadsheet->getSheetByName('Açıklamalar'))->not->toBeNull();
    $spreadsheet->disconnectWorksheets();
    unlink($temporary);
});

it('prevents viewers from uploading catalog files', function () {
    Storage::fake('local');
    $tenant = Tenant::factory()->create();
    $viewer = importMember($tenant, TenantRole::Viewer);

    $this->actingAs($viewer)->post('/imports', ['format' => 'xml', 'file' => UploadedFile::fake()->createWithContent('urunler.xml', '<products/>')])->assertForbidden();
    expect(CatalogImport::query()->count())->toBe(0);
});
