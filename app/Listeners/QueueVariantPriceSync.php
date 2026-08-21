<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Sync\Actions\DispatchVariantSync;
use App\Events\VariantPriceChanged;

final class QueueVariantPriceSync
{
    public function __construct(private readonly DispatchVariantSync $dispatcher) {}

    public function handle(VariantPriceChanged $event): void
    {
        $this->dispatcher->execute($event->tenantId, $event->variantId, 'price_push');
    }
}
