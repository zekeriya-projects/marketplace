<?php

declare(strict_types=1);

namespace App\Domain\Channels\Enums;

enum ChannelListingStatus: string
{
    case Unmapped = 'unmapped';
    case Pending = 'pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Disabled = 'disabled';
    case Error = 'error';
}
