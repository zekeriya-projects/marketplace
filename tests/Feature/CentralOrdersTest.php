<?php

declare(strict_types=1);

use App\Domain\Channels\Actions\ImportWooCommerceProduct;
use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Orders\Actions\IngestOrder;
use App\Domain\Orders\DTO\NormalizedOrder;
use App\Domain\Orders\DTO\NormalizedOrderItem;
use App\Domain\Orders\Enums\OrderItemMappingStatus;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Support\OrderStatusCapabilities;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Integrations\ConnectorManager;
use App\Integrations\WooCommerce\WooCommerceCatalogImporter;
use App\Integrations\WooCommerce\WooCommerceClient;
use App\Integrations\WooCommerce\WooCommerceConnector;
use App\Integrations\WooCommerce\WooCommerceUrlGuard;
use App\Jobs\SyncOrderStatusJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncOperation;
use App\Models\Tenant;

it('offers shipment only when the WooCommerce account has a real provider mapping', function () {
    $tenant = Tenant::factory()->create();
    $account = ChannelAccount::factory()->for($tenant)->create(['channel_id' => Channel::query()->where('code', 'woocommerce')->sole()->id, 'settings' => []]);
    $capabilities = app(OrderStatusCapabilities::class);

    expect(array_column($capabilities->available($account->load('channel'), OrderStatus::Processing), 'value'))->not->toContain('shipped');
    $account->update(['settings' => ['order_status_mappings' => ['shipped' => 'wc-shipped']]]);
    expect($capabilities->externalStatus($account->fresh(), OrderStatus::Shipped))->toBe('wc-shipped')
        ->and($capabilities->available($account->fresh()->load('channel'), OrderStatus::Processing))->toContain(['value' => 'shipped', 'label' => 'Kargoya teslim edildi', 'provider_status' => 'wc-shipped', 'meaning' => 'Pazaryerine “wc-shipped” olarak gönderilir.']);
});
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

function normalizedOrder(array $overrides = []): NormalizedOrder
{
    return new NormalizedOrder(
        externalOrderId: $overrides['externalOrderId'] ?? 'remote-1001',
        externalOrderNumber: $overrides['externalOrderNumber'] ?? 'WC-1001',
        status: $overrides['status'] ?? OrderStatus::Pending,
        externalStatus: $overrides['externalStatus'] ?? 'on-hold',
        currency: $overrides['currency'] ?? 'TRY',
        subtotalAmount: $overrides['subtotalAmount'] ?? 10000,
        discountAmount: $overrides['discountAmount'] ?? 500,
        shippingAmount: $overrides['shippingAmount'] ?? 1000,
        taxAmount: $overrides['taxAmount'] ?? 1800,
        totalAmount: $overrides['totalAmount'] ?? 12300,
        customer: $overrides['customer'] ?? ['name' => 'Ada Lovelace', 'email' => 'ada@example.test'],
        shippingAddress: $overrides['shippingAddress'] ?? ['address_1' => 'Main Street 1', 'city' => 'Istanbul', 'country' => 'TR'],
        billingAddress: $overrides['billingAddress'] ?? null,
        orderedAt: $overrides['orderedAt'] ?? new DateTimeImmutable('2026-08-10T10:00:00+03:00'),
        items: $overrides['items'] ?? [new NormalizedOrderItem('Mapped shoe', 1, 10000, 500, 1800, 11300, 'line-1', '55', '77', 'SKU-55', '86955')],
    );
}

it('ingests a mapped normalized order with exact snapshots and minor units', function () {
    $tenant = Tenant::factory()->create();
    $account = wooAccount($tenant);
    $listing = orderListing($tenant, $account);

    $order = app(IngestOrder::class)->execute($account, normalizedOrder());
    $item = $order->items->sole();

    expect($order->tenant_id)->toBe($tenant->id)->and($order->total_amount)->toBe(12300)
        ->and($order->customer_snapshot['email'])->toBe('ada@example.test')
        ->and($item->mapping_status)->toBe(OrderItemMappingStatus::Mapped)
        ->and($item->channel_listing_id)->toBe($listing->id)
        ->and($item->product_variant_id)->toBe($listing->product_variant_id);
});

it('updates the same external order idempotently without duplicating order or items', function () {
    $account = wooAccount(Tenant::factory()->create());
    orderListing($account->tenant, $account);
    $action = app(IngestOrder::class);
    $first = $action->execute($account, normalizedOrder());
    $second = $action->execute($account, normalizedOrder(['status' => OrderStatus::Processing, 'totalAmount' => 13000, 'items' => [new NormalizedOrderItem('Updated shoe', 2, 6500, 0, 0, 13000, 'line-1', '55', '77', 'SKU-55')]]));

    expect($second->id)->toBe($first->id)->and(Order::query()->count())->toBe(1)->and($second->items()->count())->toBe(1)
        ->and($second->status)->toBe(OrderStatus::Processing)->and($second->items()->sole()->quantity)->toBe(2);
});

it('persists unmapped external item identity and detects ambiguous fallback mappings', function () {
    $tenant = Tenant::factory()->create();
    $account = wooAccount($tenant);
    orderListing($tenant, $account, ['external_product_id' => '1', 'external_variant_id' => '1', 'external_sku' => 'DUPLICATE']);
    orderListing($tenant, $account, ['external_product_id' => '2', 'external_variant_id' => '2', 'external_sku' => 'DUPLICATE']);
    $items = [
        new NormalizedOrderItem('Unknown', 1, 100, 0, 0, 100, 'u1', 'missing', null, 'MISSING', '999'),
        new NormalizedOrderItem('Ambiguous', 1, 200, 0, 0, 200, 'u2', null, null, 'DUPLICATE'),
    ];

    $order = app(IngestOrder::class)->execute($account, normalizedOrder(['items' => $items]));
    $unmapped = $order->items->firstWhere('external_item_id', 'u1');
    $ambiguous = $order->items->firstWhere('external_item_id', 'u2');

    expect($unmapped->mapping_status)->toBe(OrderItemMappingStatus::Unmapped)->and($unmapped->external_product_id)->toBe('missing')->and($unmapped->product_variant_id)->toBeNull()
        ->and($ambiguous->mapping_status)->toBe(OrderItemMappingStatus::Ambiguous)->and($ambiguous->channel_listing_id)->toBeNull();
});

it('lists filters and paginates only active tenant orders', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = tenantMember($tenant);
    $account = wooAccount($tenant);
    $otherAccount = wooAccount($other);
    Order::factory()->for($account, 'account')->count(26)->create(['tenant_id' => $tenant->id, 'status' => OrderStatus::Pending]);
    Order::factory()->for($otherAccount, 'account')->create(['tenant_id' => $other->id]);

    $this->actingAs($user)->get("/orders?channel_account={$account->id}&status=pending")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Orders/Index')->has('orders.data', 25)->where('orders.total', 26)->where('filters.channel_account', $account->id));
});

it('prevents cross tenant order reads and database item relationships', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = tenantMember($tenant);
    $account = wooAccount($other);
    $order = Order::factory()->for($account, 'account')->create(['tenant_id' => $other->id]);
    $foreignProduct = Product::factory()->for($tenant)->create();
    $foreignVariant = ProductVariant::factory()->for($foreignProduct)->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->get("/orders/{$order->id}")->assertForbidden();
    expect(fn () => $order->items()->create([
        'tenant_id' => $other->id, 'product_variant_id' => $foreignVariant->id, 'name' => 'Cross tenant', 'quantity' => 1,
        'unit_price_amount' => 100, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 100,
    ]))->toThrow(QueryException::class);
});

it('queues and confirms a WooCommerce order status update before changing the central order', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant, TenantRole::Operator);
    $account = wooAccount($tenant, [
        'status' => ChannelAccountStatus::Active,
        'credentials_encrypted' => wooCredentialsPayload(),
    ]);
    $order = Order::factory()->for($account, 'account')->create(['tenant_id' => $tenant->id, 'external_order_id' => '2606', 'status' => OrderStatus::Pending]);

    $this->actingAs($user)->put("/orders/{$order->id}/status", ['status' => 'processing'])->assertRedirect();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(SyncOperation::query()->where('operation', 'order_status_push')->sole()->context['target_status'])->toBe('processing');
    Queue::assertPushed(SyncOrderStatusJob::class);

    $guard = Mockery::mock(WooCommerceUrlGuard::class);
    $guard->shouldReceive('assertSafe')->andReturnNull();
    $client = new WooCommerceClient($guard);
    app(ConnectorManager::class)->register('woocommerce', new WooCommerceConnector($client, new WooCommerceCatalogImporter($client, app(ImportWooCommerceProduct::class))));
    Http::fake(fn () => Http::response(['id' => 2606, 'status' => 'processing']));
    $operation = SyncOperation::query()->sole();
    (new SyncOrderStatusJob($operation->id))->handle();
    $operation->refresh();

    expect($operation->status->value)->toBe('succeeded', "{$operation->error_code}: {$operation->safe_error_message}")
        ->and($order->fresh()->status)->toBe(OrderStatus::Processing)
        ->and($order->fresh()->external_status)->toBe('processing')
        ->and($operation->fresh()->status->value)->toBe('succeeded');
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_contains($request->url(), '/orders/2606') && $request['status'] === 'processing');
});

it('prevents viewers and other tenants from changing an order status', function () {
    $tenant = Tenant::factory()->create();
    $account = wooAccount($tenant);
    $order = Order::factory()->for($account, 'account')->create(['tenant_id' => $tenant->id]);

    $this->actingAs(tenantMember($tenant, TenantRole::Viewer))->put("/orders/{$order->id}/status", ['status' => 'processing'])->assertForbidden();
    $this->actingAs(tenantMember(Tenant::factory()->create()))->put("/orders/{$order->id}/status", ['status' => 'processing'])->assertForbidden();
    expect(SyncOperation::query()->count())->toBe(0);
});
