<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Sync\Actions\DispatchVariantSync;
use App\Events\InventoryChanged;

final class QueueVariantInventorySync
{
    public function __construct(private readonly DispatchVariantSync $dispatcher) {}

    public function handle(InventoryChanged $event): void
    {
        $this->dispatcher->execute($event->tenantId, $event->variantId, 'inventory_push', $event->excludedChannelAccountId);
    }
}
