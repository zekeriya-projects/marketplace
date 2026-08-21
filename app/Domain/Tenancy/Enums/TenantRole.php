<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function canManageTenant(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
