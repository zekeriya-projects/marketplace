<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\Enums\TenantRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CatalogReferencePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->has() && $user->belongsToTenant($this->context->get());
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) && $model->getAttribute('tenant_id') === $this->context->get()->getKey();
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->view($user, $model) && $this->canWrite($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    private function canWrite(User $user): bool
    {
        $role = $this->context->has() ? $user->roleFor($this->context->get()) : null;

        return $role instanceof TenantRole && $role !== TenantRole::Viewer;
    }
}
