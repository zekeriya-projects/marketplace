<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Support\OrderStatusCapabilities;
use App\Domain\Sync\Actions\CreateSyncOperation;
use App\Jobs\SyncOrderStatusJob;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

final class QueueOrderStatusUpdate
{
    public function __construct(private readonly CreateSyncOperation $createSyncOperation, private readonly OrderStatusCapabilities $capabilities) {}

    public function execute(Order $order, OrderStatus $status): void
    {
        $order->loadMissing('account.channel');
        $available = $this->capabilities->available($order->account, $order->status);
        if (! in_array($status->value, array_column($available, 'value'), true)) {
            throw ValidationException::withMessages(['status' => 'Bu durum seçili pazaryeri ve mevcut sipariş aşaması için kullanılamaz.']);
        }

        $existing = $order->account->syncOperations()->where('operation', 'order_status_push')->where('entity_type', $order->getMorphClass())->where('entity_id', $order->id)->whereIn('status', ['pending', 'running'])->exists();
        if ($existing) {
            throw ValidationException::withMessages(['status' => 'Bu sipariş için devam eden bir durum güncellemesi var.']);
        }

        $operation = $this->createSyncOperation->execute($order->account, 'order_status_push', $order, ['from_status' => $order->status->value, 'target_status' => $status->value]);
        SyncOrderStatusJob::dispatch($operation->id);
    }
}
