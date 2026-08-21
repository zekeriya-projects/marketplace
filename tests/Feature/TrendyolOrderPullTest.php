<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Inventory\Enums\InventoryMovementType;
use App\Domain\Orders\Actions\DispatchTrendyolOrderPull;
use App\Domain\Orders\Enums\OrderItemMappingStatus;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Integrations\Trendyol\TrendyolOrderImporter;
use App\Integrations\Trendyol\TrendyolOrderMapper;
use App\Jobs\PullTrendyolOrdersPageJob;
use App\Jobs\SyncVariantInventoryJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\ChannelSyncState;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderInventoryAllocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncOperation;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function trendyolOrderPayload(array $overrides = []): array
{
    return [
        'id' => 9001, 'orderNumber' => 'TY-1001', 'currencyCode' => 'TRY', 'shipmentPackageStatus' => 'Created',
        'orderDate' => 1786406400000, 'packageTotalPrice' => 125.50,
        'shipmentAddress' => ['firstName' => 'Ayşe', 'lastName' => 'Yılmaz', 'fullAddress' => 'Test adresi', 'city' => 'İstanbul', 'district' => 'Kadıköy'],
        'invoiceAddress' => ['firstName' => 'Ayşe', 'lastName' => 'Yılmaz', 'fullAddress' => 'Fatura adresi', 'city' => 'İstanbul'],
        'lines' => [['lineId' => 7001, 'productName' => 'Siyah Ayakkabı', 'quantity' => 1, 'lineUnitPrice' => 150.00, 'amount' => 125.50, 'merchantSku' => 'SKU-TY', 'barcode' => '8690000123']],
        ...$overrides,
    ];
}

function trendyolOrderFixture(): array
{
    $tenant = Tenant::factory()->create();
    $trendyol = trendyolAccount($tenant, ['status' => ChannelAccountStatus::Active, 'credentials_encrypted' => trendyolCredentials()]);
    $woocommerce = ChannelAccount::factory()->for($tenant)->create(['channel_id' => Channel::query()->where('code', 'woocommerce')->sole()->id, 'status' => ChannelAccountStatus::Active, 'credentials_encrypted' => ['store_url' => 'https://shop.example.com', 'consumer_key' => 'ck_'.str_repeat('a', 40), 'consumer_secret' => 'cs_'.str_repeat('b', 40)], 'settings' => ['currency' => 'TRY']]);
    $product = Product::factory()->for($tenant)->create();
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id, 'sku' => 'SKU-TY', 'barcode' => '8690000123', 'currency' => 'TRY']);
    $trendyolListing = ChannelListing::query()->create(['tenant_id' => $tenant->id, 'channel_account_id' => $trendyol->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'external_barcode' => $variant->barcode, 'external_sku' => $variant->sku, 'status' => ChannelListingStatus::Active]);
    ChannelListing::query()->create(['tenant_id' => $tenant->id, 'channel_account_id' => $woocommerce->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'external_product_id' => '55', 'external_sku' => $variant->sku, 'status' => ChannelListingStatus::Active]);
    $warehouse = Warehouse::factory()->for($tenant)->create(['is_default' => true, 'is_active' => true]);
    InventoryItem::query()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => 10, 'reserved_quantity' => 0]);

    return compact('tenant', 'trendyol', 'woocommerce', 'variant', 'trendyolListing', 'warehouse');
}

it('normalizes Trendyol package money status identity and snapshots', function () {
    $order = app(TrendyolOrderMapper::class)->map(trendyolOrderPayload(['shipmentPackageStatus' => 'Shipped']));

    expect($order->externalOrderId)->toBe('9001')->and($order->externalOrderNumber)->toBe('TY-1001')
        ->and($order->status->value)->toBe('shipped')->and($order->subtotalAmount)->toBe(15000)
        ->and($order->discountAmount)->toBe(2450)->and($order->totalAmount)->toBe(12550)
        ->and($order->items[0]->externalBarcode)->toBe('8690000123')->and($order->shippingAddress['city'])->toBe('İstanbul');
});

it('imports a mapped pending Trendyol order once, reserves stock, and queues available stock only for WooCommerce', function () {
    Queue::fake();
    ['tenant' => $tenant, 'trendyol' => $trendyol, 'woocommerce' => $woocommerce, 'variant' => $variant, 'warehouse' => $warehouse] = trendyolOrderFixture();
    Http::fake(['*/orders/stream*' => Http::response(['content' => [trendyolOrderPayload()], 'nextCursor' => null])]);

    $first = app(DispatchTrendyolOrderPull::class)->execute($trendyol);
    (new PullTrendyolOrdersPageJob($first->id))->handle(app(TrendyolOrderImporter::class));
    $second = app(DispatchTrendyolOrderPull::class)->execute($trendyol);
    (new PullTrendyolOrdersPageJob($second->id))->handle(app(TrendyolOrderImporter::class));

    expect(Order::query()->count())->toBe(1)->and(InventoryMovement::query()->count())->toBe(0)
        ->and(InventoryItem::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->value('quantity'))->toBe(10)
        ->and(InventoryItem::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->value('reserved_quantity'))->toBe(1)
        ->and(OrderInventoryAllocation::query()->sole()->reserved_quantity)->toBe(1)
        ->and(SyncOperation::query()->where('operation', 'inventory_push')->count())->toBe(1)
        ->and(SyncOperation::query()->where('operation', 'inventory_push')->sole()->channel_account_id)->toBe($woocommerce->id);
    Queue::assertPushed(SyncVariantInventoryJob::class, 1);
});

it('restores Trendyol returned inventory once across repeated imports', function () {
    Queue::fake();
    ['trendyol' => $trendyol, 'variant' => $variant, 'warehouse' => $warehouse] = trendyolOrderFixture();
    Http::fakeSequence('*/orders/stream*')
        ->push(['content' => [trendyolOrderPayload(['shipmentPackageStatus' => 'Picking'])], 'nextCursor' => null])
        ->push(['content' => [trendyolOrderPayload(['shipmentPackageStatus' => 'Returned'])], 'nextCursor' => null])
        ->push(['content' => [trendyolOrderPayload(['shipmentPackageStatus' => 'Returned'])], 'nextCursor' => null]);
    expect(app(TrendyolOrderImporter::class)->importPage($trendyol, 1, 2, null)->successful)->toBeTrue();

    expect(app(TrendyolOrderImporter::class)->importPage($trendyol, 1, 2, null)->successful)->toBeTrue()
        ->and(app(TrendyolOrderImporter::class)->importPage($trendyol, 1, 2, null)->successful)->toBeTrue()
        ->and(InventoryItem::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->value('quantity'))->toBe(10)
        ->and(InventoryMovement::query()->where('type', InventoryMovementType::Return)->count())->toBe(1);
});

it('continues Trendyol cursor pages and advances the successful window', function () {
    Queue::fake();
    ['trendyol' => $trendyol] = trendyolOrderFixture();
    $operation = app(DispatchTrendyolOrderPull::class)->execute($trendyol);
    Http::fakeSequence()->push(['content' => [], 'nextCursor' => 'opaque-next'])->push(['content' => [], 'nextCursor' => null]);

    (new PullTrendyolOrdersPageJob($operation->id))->handle(app(TrendyolOrderImporter::class));
    expect($operation->fresh()->status)->toBe(SyncOperationStatus::Running)->and($operation->fresh()->context['cursor'])->toBe('opaque-next');
    (new PullTrendyolOrdersPageJob($operation->id, 'opaque-next'))->handle(app(TrendyolOrderImporter::class));

    expect($operation->fresh()->status)->toBe(SyncOperationStatus::Succeeded)->and($operation->fresh()->context['processed_pages'])->toBe(2)
        ->and(ChannelSyncState::query()->where('channel_account_id', $trendyol->id)->sole()->last_synced_to)->not->toBeNull();
});

it('retains unmapped Trendyol lines without changing inventory', function () {
    ['trendyol' => $trendyol] = trendyolOrderFixture();
    Http::fake(['*' => Http::response(['content' => [trendyolOrderPayload(['lines' => [['lineId' => 8, 'productName' => 'Bilinmeyen', 'quantity' => 2, 'lineUnitPrice' => 10, 'amount' => 20, 'merchantSku' => 'UNKNOWN', 'barcode' => 'UNKNOWN']]])]])]);

    $result = app(TrendyolOrderImporter::class)->importPage($trendyol, 1, 2, null);

    expect($result->context['unmapped'])->toBe(1)->and(Order::query()->sole()->items()->sole()->mapping_status)->toBe(OrderItemMappingStatus::Unmapped)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('allows operators to queue one active own-tenant Trendyol pull', function () {
    Queue::fake();
    ['tenant' => $tenant, 'trendyol' => $trendyol] = trendyolOrderFixture();
    $operator = tenantMember($tenant, TenantRole::Operator);
    $viewer = tenantMember($tenant, TenantRole::Viewer);

    $this->actingAs($viewer)->post("/channels/accounts/{$trendyol->id}/trendyol/pull-orders")->assertForbidden();
    $this->actingAs($operator)->post("/channels/accounts/{$trendyol->id}/trendyol/pull-orders")->assertRedirect("/channels/accounts/{$trendyol->id}");
    $this->actingAs($operator)->post("/channels/accounts/{$trendyol->id}/trendyol/pull-orders")->assertConflict();
    Queue::assertPushed(PullTrendyolOrdersPageJob::class, 1);
});
