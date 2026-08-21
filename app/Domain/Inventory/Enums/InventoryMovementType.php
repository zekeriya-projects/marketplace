<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

enum InventoryMovementType: string
{
    case Import = 'import';
    case ManualAdjustment = 'manual_adjustment';
    case Sale = 'sale';
    case Cancellation = 'cancellation';
    case Return = 'return';
    case Correction = 'correction';
}
