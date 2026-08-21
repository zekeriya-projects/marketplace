<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

final class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->roleFor($tenant)?->canManageTenant() === true;
    }

    public function switch(User $user, Tenant $tenant): bool
    {
        return $this->view($user, $tenant);
    }

    public function manageMembers(User $user, Tenant $tenant): bool
    {
        return $user->roleFor($tenant)?->canManageTenant() === true;
    }
}
