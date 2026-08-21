<?php

declare(strict_types=1);

namespace App\Domain\Channels\Enums;

enum PublicationStatus: string
{
    case Queued = 'queued';
    case AlreadyActive = 'already_active';
    case Skipped = 'skipped';
    case Invalid = 'invalid';
}
