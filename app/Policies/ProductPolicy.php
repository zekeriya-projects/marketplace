<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\Enums\TenantRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\User;

final class ProductPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->has() && $user->belongsToTenant($this->context->get());
    }

    public function view(User $user, Product $product): bool
    {
        return $this->viewAny($user) && $product->tenant_id === $this->context->get()->getKey();
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->view($user, $product) && $this->canWrite($user);
    }

    private function canWrite(User $user): bool
    {
        $role = $this->context->has() ? $user->roleFor($this->context->get()) : null;

        return $role instanceof TenantRole && $role !== TenantRole::Viewer;
    }
}
