<?php

declare(strict_types=1);

namespace App\Domain\Orders\Enums;

enum OrderItemMappingStatus: string
{
    case Mapped = 'mapped';
    case Unmapped = 'unmapped';
    case Ambiguous = 'ambiguous';
}
