<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Inventory\Enums\InventoryMovementType;
use App\Domain\Orders\Actions\ApplyOrderInventory;
use App\Domain\Orders\Actions\IngestOrder;
use App\Domain\Sync\Actions\CreateSyncOperation;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Integrations\WooCommerce\WooCommerceClient;
use App\Integrations\WooCommerce\WooCommerceOrderImporter;
use App\Integrations\WooCommerce\WooCommerceOrderMapper;
use App\Integrations\WooCommerce\WooCommerceUrlGuard;
use App\Jobs\PullWooCommerceOrdersPageJob;
use App\Models\ChannelSyncState;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function wooOrderPayload(array $overrides = []): array
{
    return [
        'id' => 9001, 'number' => 'WC-9001', 'status' => 'processing', 'currency' => 'TRY',
        'date_created_gmt' => '2026-08-10T10:00:00', 'discount_total' => '10.00', 'shipping_total' => '25.00', 'total_tax' => '18.00', 'total' => '133.00',
        'billing' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'phone' => '555'],
        'shipping' => ['address_1' => 'Main 1', 'city' => 'Istanbul', 'country' => 'TR'],
        'line_items' => [['id' => 91, 'name' => 'Mapped shoe', 'product_id' => 55, 'variation_id' => 77, 'quantity' => 2, 'subtotal' => '100.00', 'total' => '90.00', 'total_tax' => '18.00', 'sku' => 'SKU-55']],
        ...$overrides,
    ];
}

function orderImporter(): WooCommerceOrderImporter
{
    $guard = Mockery::mock(WooCommerceUrlGuard::class);
    $guard->shouldReceive('assertSafe')->andReturnNull();

    return new WooCommerceOrderImporter(new WooCommerceClient($guard), new WooCommerceOrderMapper, app(IngestOrder::class), app(ApplyOrderInventory::class));
}

it('maps WooCommerce money snapshots without floating point values', function () {
    $order = (new WooCommerceOrderMapper)->map(wooOrderPayload());
    expect($order->subtotalAmount)->toBe(10000)->and($order->discountAmount)->toBe(1000)->and($order->shippingAmount)->toBe(2500)
        ->and($order->taxAmount)->toBe(1800)->and($order->totalAmount)->toBe(13300)
        ->and($order->items[0]->unitPriceAmount)->toBe(5000)->and($order->items[0]->totalAmount)->toBe(10800);
});

it('imports a mapped order and reduces default warehouse stock exactly once', function () {
    $tenant = Tenant::factory()->create();
    $account = wooAccount($tenant, ['status' => ChannelAccountStatus::Active, 'credentials_encrypted' => wooCredentialsPayload(['store_url' => 'https://shop.example.com'])]);
    $listing = orderListing($tenant, $account);
    $warehouse = Warehouse::factory()->for($tenant)->create(['is_default' => true, 'is_active' => true]);
    InventoryItem::query()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $listing->product_variant_id, 'quantity' => 10, 'reserved_quantity' => 0]);
    Http::fake(['*/orders?*' => Http::response([wooOrderPayload()], 200, ['X-WP-TotalPages' => '1'])]);
    $importer = orderImporter();

    expect($importer->importPage($account, 1, '2026-08-01T00:00:00Z', '2026-08-11T00:00:00Z')->successful)->toBeTrue();
    expect($importer->importPage($account->fresh(), 1, '2026-08-01T00:00:00Z', '2026-08-11T00:00:00Z')->successful)->toBeTrue();

    expect(Order::query()->count())->toBe(1)->and(Order::query()->sole()->inventory_applied_at)->not->toBeNull()
        ->and(InventoryItem::query()->sole()->quantity)->toBe(8)->and(InventoryMovement::query()->count())->toBe(1)
        ->and(InventoryMovement::query()->sole()->type)->toBe(InventoryMovementType::Sale);
});

it('restores WooCommerce cancelled inventory once across repeated imports', function () {
    $tenant = Tenant::factory()->create();
    $account = wooAccount($tenant, ['status' => ChannelAccountStatus::Active, 'credentials_encrypted' => wooCredentialsPayload(['store_url' => 'https://shop.example.com'])]);
    $listing = orderListing($tenant, $account);
    $warehouse = Warehouse::factory()->for($tenant)->create(['is_default' => true, 'is_active' => true]);
    InventoryItem::query()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $listing->product_variant_id, 'quantity' => 10, 'reserved_quantity' => 0]);
    $importer = orderImporter();

    Http::fakeSequence('*/orders?*')
        ->push([wooOrderPayload()], 200, ['X-WP-TotalPages' => '1'])
        ->push([wooOrderPayload(['status' => 'cancelled'])], 200, ['X-WP-TotalPages' => '1'])
        ->push([wooOrderPayload(['status' => 'cancelled'])], 200, ['X-WP-TotalPages' => '1']);
    expect($importer->importPage($account, 1, '2026-08-01T00:00:00Z', '2026-08-11T00:00:00Z')->successful)->toBeTrue();
    expect($importer->importPage($account, 1, '2026-08-01T00:00:00Z', '2026-08-11T00:00:00Z')->successful)->toBeTrue()
        ->and($importer->importPage($account, 1, '2026-08-01T00:00:00Z', '2026-08-11T00:00:00Z')->successful)->toBeTrue()
        ->and(InventoryItem::query()->sole()->quantity)->toBe(10)
        ->and(InventoryMovement::query()->where('type', InventoryMovementType::Cancellation)->count())->toBe(1);
});

it('retains unmapped orders without changing inventory', function () {
    $tenant = Tenant::factory()->create();
    $account = wooAccount($tenant, ['status' => ChannelAccountStatus::Active, 'credentials_encrypted' => wooCredentialsPayload(['store_url' => 'https://shop.example.com'])]);
    Http::fake(['*/orders?*' => Http::response([wooOrderPayload()], 200, ['X-WP-TotalPages' => '1'])]);

    $result = orderImporter()->importPage($account, 1, '2026-08-01T00:00:00Z', '2026-08-11T00:00:00Z');

    expect($result->context['unmapped'])->toBe(1)->and(Order::query()->count())->toBe(1)->and(InventoryMovement::query()->count())->toBe(0)
        ->and(Order::query()->sole()->items()->sole()->external_product_id)->toBe('55');
});

it('fans out pages and advances the order window only after successful completion', function () {
    Queue::fake();
    $account = wooAccount(Tenant::factory()->create(), ['status' => ChannelAccountStatus::Active, 'credentials_encrypted' => wooCredentialsPayload(['store_url' => 'https://shop.example.com'])]);
    $operation = app(CreateSyncOperation::class)->execute($account, 'order_pull', context: ['from' => '2026-08-01T00:00:00Z', 'to' => '2026-08-11T00:00:00Z', 'total_pages' => 1, 'processed_pages' => [], 'imported' => 0, 'failed' => 0, 'unmapped' => 0]);
    Http::fake(['*/orders?*' => Http::response([], 200, ['X-WP-TotalPages' => '2'])]);
    $job = new PullWooCommerceOrdersPageJob($operation->id);
    $job->handle(orderImporter());

    expect($operation->fresh()->status)->toBe(SyncOperationStatus::Running)->and(ChannelSyncState::query()->count())->toBe(0);
    Queue::assertPushed(fn (PullWooCommerceOrdersPageJob $queued) => $queued->page === 2);
    (new PullWooCommerceOrdersPageJob($operation->id, 2))->handle(orderImporter());
    expect($operation->fresh()->status)->toBe(SyncOperationStatus::Succeeded)->and(ChannelSyncState::query()->sole()->last_synced_to->toIso8601String())->toBe('2026-08-11T00:00:00+00:00');
});

it('allows operators to queue one active own-tenant order pull', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $operator = tenantMember($tenant, TenantRole::Operator);
    $viewer = tenantMember($tenant, TenantRole::Viewer);
    $account = wooAccount($tenant, ['status' => ChannelAccountStatus::Active, 'credentials_encrypted' => wooCredentialsPayload()]);

    $this->actingAs($operator)->post("/channels/accounts/{$account->id}/woocommerce/pull-orders")->assertRedirect();
    $this->actingAs($operator)->post("/channels/accounts/{$account->id}/woocommerce/pull-orders")->assertStatus(409);
    $this->actingAs($viewer)->post("/channels/accounts/{$account->id}/woocommerce/pull-orders")->assertForbidden();
    expect($account->syncOperations()->where('operation', 'order_pull')->count())->toBe(1);
    Queue::assertPushed(PullWooCommerceOrdersPageJob::class, 1);
});
