<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Inventory\Enums\InventoryMovementType;
use App\Domain\Orders\Actions\ApplyOrderInventory;
use App\Domain\Orders\Actions\CompensateOrderInventory;
use App\Domain\Orders\Actions\IngestOrder;
use App\Domain\Orders\DTO\NormalizedOrder;
use App\Domain\Orders\DTO\NormalizedOrderItem;
use App\Domain\Orders\Enums\OrderStatus;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderInventoryAllocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

function compensationFixture(int $quantity = 4, int $stock = 10, string $channelCode = 'woocommerce'): array
{
    $tenant = Tenant::factory()->create();
    $account = ChannelAccount::factory()->for($tenant)->create(['channel_id' => Channel::query()->where('code', $channelCode)->sole()->id]);
    $product = Product::factory()->for($tenant)->create();
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id]);
    ChannelListing::query()->create([
        'tenant_id' => $tenant->id,
        'channel_account_id' => $account->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'external_product_id' => 'remote-product',
        'external_sku' => $variant->sku,
        'status' => ChannelListingStatus::Active,
    ]);
    $warehouse = Warehouse::factory()->for($tenant)->create(['is_default' => true]);
    InventoryItem::query()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => $stock, 'reserved_quantity' => 0]);

    $makeOrder = fn (OrderStatus $status, string $externalStatus = 'processing'): NormalizedOrder => new NormalizedOrder(
        externalOrderId: 'remote-order',
        externalOrderNumber: 'ORDER-1',
        status: $status,
        externalStatus: $externalStatus,
        currency: 'TRY',
        subtotalAmount: 1000 * $quantity,
        discountAmount: 0,
        shippingAmount: 0,
        taxAmount: 0,
        totalAmount: 1000 * $quantity,
        customer: [],
        shippingAddress: [],
        billingAddress: null,
        orderedAt: new DateTimeImmutable('2026-08-19T10:00:00Z'),
        items: [new NormalizedOrderItem('Product', $quantity, 1000, 0, 0, 1000 * $quantity, externalProductId: 'remote-product', externalSku: $variant->sku)],
    );

    $order = app(IngestOrder::class)->execute($account, $makeOrder(OrderStatus::Processing));
    app(ApplyOrderInventory::class)->execute($order);

    return compact('tenant', 'account', 'variant', 'warehouse', 'order', 'makeOrder');
}

it('restores a cancelled order exactly once', function () {
    ['account' => $account, 'variant' => $variant, 'warehouse' => $warehouse, 'makeOrder' => $makeOrder] = compensationFixture();
    expect(OrderInventoryAllocation::query()->sole()->sold_quantity)->toBe(4)
        ->and(OrderInventoryAllocation::query()->sole()->remainingQuantity())->toBe(4)
        ->and(Order::query()->sole()->inventory_applied_at)->not->toBeNull();
    $cancelled = app(IngestOrder::class)->execute($account, $makeOrder(OrderStatus::Cancelled, 'cancelled'));

    expect(app(ApplyOrderInventory::class)->execute($cancelled))->toBeTrue()
        ->and(app(ApplyOrderInventory::class)->execute($cancelled->fresh()))->toBeFalse()
        ->and(InventoryItem::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->value('quantity'))->toBe(10)
        ->and(InventoryMovement::query()->where('type', InventoryMovementType::Cancellation)->count())->toBe(1)
        ->and(OrderInventoryAllocation::query()->sole()->cancelled_quantity)->toBe(4);
});

it('supports bounded partial and full return compensation', function () {
    ['order' => $order, 'variant' => $variant, 'warehouse' => $warehouse] = compensationFixture();
    $action = app(CompensateOrderInventory::class);

    expect($action->execute($order, InventoryMovementType::Return, [$variant->id => 2]))->toBeTrue()
        ->and(InventoryItem::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->value('quantity'))->toBe(8)
        ->and(OrderInventoryAllocation::query()->sole()->returned_quantity)->toBe(2);

    expect(fn () => $action->execute($order, InventoryMovementType::Return, [$variant->id => 3]))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($order, InventoryMovementType::Return, [$variant->id => -1]))->toThrow(ValidationException::class);
    expect(InventoryItem::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->value('quantity'))->toBe(8)
        ->and(OrderInventoryAllocation::query()->sole()->returned_quantity)->toBe(2)
        ->and($action->execute($order, InventoryMovementType::Return))->toBeTrue()
        ->and(InventoryItem::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->value('quantity'))->toBe(10)
        ->and($action->execute($order, InventoryMovementType::Return))->toBeFalse();
});

it('does not mutate stock for an order first imported as cancelled or for unmapped lines', function () {
    $tenant = Tenant::factory()->create();
    $account = ChannelAccount::factory()->for($tenant)->create(['channel_id' => Channel::query()->where('code', 'woocommerce')->sole()->id]);
    $order = app(IngestOrder::class)->execute($account, new NormalizedOrder(
        'cancelled-before-import', null, OrderStatus::Cancelled, 'cancelled', 'TRY', 1000, 0, 0, 0, 1000, [], [], null,
        new DateTimeImmutable('2026-08-19T10:00:00Z'),
        [new NormalizedOrderItem('Unmapped', 1, 1000, 0, 0, 1000, externalSku: 'UNKNOWN')],
    ));

    expect(app(ApplyOrderInventory::class)->execute($order))->toBeFalse()
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(OrderInventoryAllocation::query()->count())->toBe(0);
});

it('rejects cross-tenant compensation references', function () {
    ['order' => $order] = compensationFixture();
    $order->tenant_id = Tenant::factory()->create()->id;

    expect(fn () => app(CompensateOrderInventory::class)->execute($order, InventoryMovementType::Cancellation))->toThrow(ModelNotFoundException::class)
        ->and(InventoryMovement::query()->where('type', InventoryMovementType::Cancellation)->count())->toBe(0);
});

it('rolls back every compensation change when one inventory row cannot be reconciled', function () {
    ['tenant' => $tenant, 'account' => $account, 'order' => $order, 'variant' => $firstVariant, 'warehouse' => $warehouse] = compensationFixture(2);
    $secondProduct = Product::factory()->for($tenant)->create();
    $secondVariant = ProductVariant::factory()->for($secondProduct)->create(['tenant_id' => $tenant->id]);
    $secondStock = InventoryItem::query()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $secondVariant->id, 'quantity' => 5, 'reserved_quantity' => 0]);
    OrderInventoryAllocation::query()->create(['tenant_id' => $tenant->id, 'order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $secondVariant->id, 'ordered_quantity' => 1, 'sold_quantity' => 1]);
    $secondStock->delete();
    $before = InventoryItem::query()->where('product_variant_id', $firstVariant->id)->value('quantity');

    expect(fn () => app(CompensateOrderInventory::class)->execute($order, InventoryMovementType::Return, [$firstVariant->id => 1, $secondVariant->id => 1]))->toThrow(ModelNotFoundException::class)
        ->and(InventoryItem::query()->where('product_variant_id', $firstVariant->id)->value('quantity'))->toBe($before)
        ->and(OrderInventoryAllocation::query()->where('product_variant_id', $firstVariant->id)->value('returned_quantity'))->toBe(0)
        ->and(InventoryMovement::query()->where('type', InventoryMovementType::Return)->count())->toBe(0);
});
