<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Inventory\Enums\InventoryMovementType;
use App\Domain\Orders\Actions\ApplyOrderInventory;
use App\Domain\Orders\Actions\IngestOrder;
use App\Domain\Orders\DTO\NormalizedOrder;
use App\Domain\Orders\DTO\NormalizedOrderItem;
use App\Domain\Orders\Enums\OrderStatus;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\OrderInventoryAllocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\ModelNotFoundException;

function reservationFixture(int $quantity = 4, int $stock = 10): array
{
    $tenant = Tenant::factory()->create();
    $account = ChannelAccount::factory()->for($tenant)->create(['channel_id' => Channel::query()->where('code', 'woocommerce')->sole()->id]);
    $product = Product::factory()->for($tenant)->create();
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $tenant->id]);
    ChannelListing::query()->create([
        'tenant_id' => $tenant->id,
        'channel_account_id' => $account->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'external_product_id' => 'reservation-product',
        'external_sku' => $variant->sku,
        'status' => ChannelListingStatus::Active,
    ]);
    $warehouse = Warehouse::factory()->for($tenant)->create(['is_default' => true, 'is_active' => true]);
    InventoryItem::query()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => $stock, 'reserved_quantity' => 0]);
    $makeOrder = fn (string $externalId, OrderStatus $status): NormalizedOrder => new NormalizedOrder(
        $externalId, $externalId, $status, $status->value, 'TRY', 1000 * $quantity, 0, 0, 0, 1000 * $quantity, [], [], null,
        new DateTimeImmutable('2026-08-20T10:00:00Z'),
        [new NormalizedOrderItem('Reserved product', $quantity, 1000, 0, 0, 1000 * $quantity, externalProductId: 'reservation-product', externalSku: $variant->sku)],
    );

    return compact('tenant', 'account', 'variant', 'warehouse', 'makeOrder');
}

it('reserves pending stock idempotently and consumes it once at processing', function () {
    ['account' => $account, 'variant' => $variant, 'warehouse' => $warehouse, 'makeOrder' => $makeOrder] = reservationFixture();
    $pending = app(IngestOrder::class)->execute($account, $makeOrder('reserve-1', OrderStatus::Pending));

    expect(app(ApplyOrderInventory::class)->execute($pending))->toBeTrue()
        ->and(app(ApplyOrderInventory::class)->execute($pending->fresh()))->toBeFalse();
    $stock = InventoryItem::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->sole();
    expect($stock->quantity)->toBe(10)->and($stock->reserved_quantity)->toBe(4)->and($stock->availableQuantity())->toBe(6)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(OrderInventoryAllocation::query()->sole()->reserved_quantity)->toBe(4);

    $processing = app(IngestOrder::class)->execute($account, $makeOrder('reserve-1', OrderStatus::Processing));
    expect(app(ApplyOrderInventory::class)->execute($processing))->toBeTrue()
        ->and(app(ApplyOrderInventory::class)->execute($processing->fresh()))->toBeFalse();
    $stock->refresh();
    $allocation = OrderInventoryAllocation::query()->sole();
    expect($stock->quantity)->toBe(6)->and($stock->reserved_quantity)->toBe(0)->and($stock->availableQuantity())->toBe(6)
        ->and($allocation->reserved_quantity)->toBe(0)->and($allocation->sold_quantity)->toBe(4)
        ->and(InventoryMovement::query()->where('type', InventoryMovementType::Sale)->count())->toBe(1);
});

it('releases an unconsumed reservation on cancellation exactly once', function () {
    ['account' => $account, 'variant' => $variant, 'warehouse' => $warehouse, 'makeOrder' => $makeOrder] = reservationFixture();
    $pending = app(IngestOrder::class)->execute($account, $makeOrder('reserve-cancel', OrderStatus::Confirmed));
    app(ApplyOrderInventory::class)->execute($pending);
    $cancelled = app(IngestOrder::class)->execute($account, $makeOrder('reserve-cancel', OrderStatus::Cancelled));

    expect(app(ApplyOrderInventory::class)->execute($cancelled))->toBeTrue()
        ->and(app(ApplyOrderInventory::class)->execute($cancelled->fresh()))->toBeFalse();
    $stock = InventoryItem::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->sole();
    $allocation = OrderInventoryAllocation::query()->sole();
    expect($stock->quantity)->toBe(10)->and($stock->reserved_quantity)->toBe(0)
        ->and($allocation->released_quantity)->toBe(4)->and($allocation->sold_quantity)->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('prevents competing orders from reserving more than available stock', function () {
    ['account' => $account, 'warehouse' => $warehouse, 'makeOrder' => $makeOrder] = reservationFixture(6, 10);
    $first = app(IngestOrder::class)->execute($account, $makeOrder('reserve-first', OrderStatus::Pending));
    $second = app(IngestOrder::class)->execute($account, $makeOrder('reserve-second', OrderStatus::Pending));

    expect(app(ApplyOrderInventory::class)->execute($first))->toBeTrue()
        ->and(fn () => app(ApplyOrderInventory::class)->execute($second))->toThrow(RuntimeException::class)
        ->and(InventoryItem::query()->where('warehouse_id', $warehouse->id)->sole()->reserved_quantity)->toBe(6)
        ->and(OrderInventoryAllocation::query()->count())->toBe(1);
});

it('rolls back a multi-variant reservation when one variant is insufficient', function () {
    ['tenant' => $tenant, 'account' => $account, 'variant' => $firstVariant, 'warehouse' => $warehouse] = reservationFixture(4, 10);
    $secondProduct = Product::factory()->for($tenant)->create();
    $secondVariant = ProductVariant::factory()->for($secondProduct)->create(['tenant_id' => $tenant->id]);
    ChannelListing::query()->create(['tenant_id' => $tenant->id, 'channel_account_id' => $account->id, 'product_id' => $secondProduct->id, 'product_variant_id' => $secondVariant->id, 'external_product_id' => 'second-product', 'external_sku' => $secondVariant->sku, 'status' => ChannelListingStatus::Active]);
    InventoryItem::query()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $secondVariant->id, 'quantity' => 1, 'reserved_quantity' => 0]);
    $order = app(IngestOrder::class)->execute($account, new NormalizedOrder(
        'reserve-rollback', null, OrderStatus::Pending, 'pending', 'TRY', 8000, 0, 0, 0, 8000, [], [], null,
        new DateTimeImmutable('2026-08-20T10:00:00Z'),
        [
            new NormalizedOrderItem('First', 4, 1000, 0, 0, 4000, externalProductId: 'reservation-product', externalSku: $firstVariant->sku),
            new NormalizedOrderItem('Second', 4, 1000, 0, 0, 4000, externalProductId: 'second-product', externalSku: $secondVariant->sku),
        ],
    ));

    expect(fn () => app(ApplyOrderInventory::class)->execute($order))->toThrow(RuntimeException::class)
        ->and(InventoryItem::query()->where('product_variant_id', $firstVariant->id)->value('reserved_quantity'))->toBe(0)
        ->and(InventoryItem::query()->where('product_variant_id', $secondVariant->id)->value('reserved_quantity'))->toBe(0)
        ->and(OrderInventoryAllocation::query()->count())->toBe(0);
});

it('cannot reconcile a reservation through another tenant context', function () {
    ['account' => $account, 'makeOrder' => $makeOrder] = reservationFixture();
    $order = app(IngestOrder::class)->execute($account, $makeOrder('reserve-tenant', OrderStatus::Pending));
    $order->tenant_id = Tenant::factory()->create()->id;

    expect(fn () => app(ApplyOrderInventory::class)->execute($order))->toThrow(ModelNotFoundException::class)
        ->and(OrderInventoryAllocation::query()->count())->toBe(0);
});
