<?php

declare(strict_types=1);

namespace App\Domain\Channels\Enums;

enum ChannelAccountStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Error = 'error';
    case Disabled = 'disabled';
}
