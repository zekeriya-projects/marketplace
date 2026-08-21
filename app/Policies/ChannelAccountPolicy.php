<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\Enums\TenantRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\ChannelAccount;
use App\Models\User;

final class ChannelAccountPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->has() && $user->belongsToTenant($this->context->get());
    }

    public function view(User $user, ChannelAccount $account): bool
    {
        return $this->viewAny($user) && $account->tenant_id === $this->context->get()->getKey();
    }

    public function create(User $user): bool
    {
        $role = $this->context->has() ? $user->roleFor($this->context->get()) : null;

        return $role instanceof TenantRole && $role !== TenantRole::Viewer;
    }

    public function update(User $user, ChannelAccount $account): bool
    {
        return $this->view($user, $account) && $this->create($user);
    }
}
