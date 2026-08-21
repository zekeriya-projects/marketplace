<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\Enums\TenantRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->has() && $user->belongsToTenant($this->context->get());
    }

    public function view(User $user, Order $order): bool
    {
        return $this->viewAny($user) && $order->tenant_id === $this->context->get()->getKey();
    }

    public function update(User $user, Order $order): bool
    {
        $role = $this->context->has() ? $user->roleFor($this->context->get()) : null;

        return $this->view($user, $order) && $role instanceof TenantRole && $role !== TenantRole::Viewer;
    }
}
