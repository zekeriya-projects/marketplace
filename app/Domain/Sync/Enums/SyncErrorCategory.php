<?php

declare(strict_types=1);

namespace App\Domain\Sync\Enums;

enum SyncErrorCategory: string
{
    case Authentication = 'authentication';
    case Validation = 'validation';
    case RateLimited = 'rate_limited';
    case Network = 'network';
    case RemoteServer = 'remote_server';
    case NotFound = 'not_found';
    case Conflict = 'conflict';
    case Unknown = 'unknown';
}
