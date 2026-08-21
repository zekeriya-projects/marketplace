<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Orders\Actions\QueueOrderStatusUpdate;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Support\OrderStatusCapabilities;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Order;
use App\Models\SyncOperation;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

it('rejects unsupported shipment without changing central state or queuing work', function () {
    $tenant = Tenant::factory()->create();
    $account = ChannelAccount::factory()->for($tenant)->create(['channel_id' => Channel::query()->where('code', 'woocommerce')->sole()->id, 'status' => ChannelAccountStatus::Active, 'settings' => []]);
    $order = Order::factory()->for($account, 'account')->create(['tenant_id' => $tenant->id, 'status' => OrderStatus::Processing]);

    expect(fn () => app(QueueOrderStatusUpdate::class)->execute($order, OrderStatus::Shipped))->toThrow(ValidationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Processing)->and(SyncOperation::query()->count())->toBe(0);
});

it('describes provider status meanings for default and custom channel mappings', function () {
    $tenant = Tenant::factory()->create();
    $account = ChannelAccount::factory()->for($tenant)->create(['channel_id' => Channel::query()->where('code', 'woocommerce')->sole()->id, 'settings' => ['order_status_mappings' => ['shipped' => 'wc-shipped']]]);
    $capabilities = app(OrderStatusCapabilities::class);

    expect($capabilities->externalStatus($account, OrderStatus::Processing))->toBe('processing')
        ->and($capabilities->externalStatus($account, OrderStatus::Shipped))->toBe('wc-shipped');
});
