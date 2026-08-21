<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManageTenantMembership
{
    public function add(User $actor, Tenant $tenant, User $member, TenantRole $role): void
    {
        $this->ensureAssignable($actor, $tenant, $role);

        if ($member->belongsToTenant($tenant)) {
            throw ValidationException::withMessages(['email' => 'This user is already an organization member.']);
        }

        $tenant->users()->attach($member, ['role' => $role->value]);
    }

    public function update(User $actor, Tenant $tenant, User $member, TenantRole $role): void
    {
        DB::transaction(function () use ($actor, $tenant, $member, $role): void {
            $currentRole = $this->membershipRole($tenant, $member);
            $this->ensureCanManageRole($actor, $tenant, $currentRole);
            $this->ensureAssignable($actor, $tenant, $role);
            $this->ensureOwnerRemains($tenant, $currentRole, $role);

            $tenant->users()->updateExistingPivot($member->getKey(), ['role' => $role->value]);
        });
    }

    public function remove(User $actor, Tenant $tenant, User $member): void
    {
        DB::transaction(function () use ($actor, $tenant, $member): void {
            $currentRole = $this->membershipRole($tenant, $member);
            $this->ensureCanManageRole($actor, $tenant, $currentRole);
            $this->ensureOwnerRemains($tenant, $currentRole, null);

            $tenant->users()->detach($member);

            if ($member->active_tenant_id === $tenant->id) {
                $replacement = $member->tenants()->orderBy('tenants.created_at')->first();
                $member->forceFill(['active_tenant_id' => $replacement?->getKey()])->save();
            }
        });
    }

    private function membershipRole(Tenant $tenant, User $member): TenantRole
    {
        return $member->roleFor($tenant)
            ?? throw ValidationException::withMessages(['member' => 'The user is not an organization member.']);
    }

    private function ensureAssignable(User $actor, Tenant $tenant, TenantRole $role): void
    {
        if ($actor->roleFor($tenant) !== TenantRole::Owner && in_array($role, [TenantRole::Owner, TenantRole::Admin], true)) {
            throw ValidationException::withMessages(['role' => 'Only an owner can assign owner or admin roles.']);
        }
    }

    private function ensureCanManageRole(User $actor, Tenant $tenant, TenantRole $targetRole): void
    {
        if ($actor->roleFor($tenant) !== TenantRole::Owner && in_array($targetRole, [TenantRole::Owner, TenantRole::Admin], true)) {
            throw ValidationException::withMessages(['role' => 'Only an owner can manage owner or admin members.']);
        }
    }

    private function ensureOwnerRemains(Tenant $tenant, TenantRole $currentRole, ?TenantRole $newRole): void
    {
        if ($currentRole !== TenantRole::Owner || $newRole === TenantRole::Owner) {
            return;
        }

        $ownerCount = DB::table('tenant_user')
            ->where('tenant_id', $tenant->getKey())
            ->where('role', TenantRole::Owner->value)
            ->lockForUpdate()
            ->get()
            ->count();

        if ($ownerCount <= 1) {
            throw ValidationException::withMessages(['role' => 'The organization must retain at least one owner.']);
        }
    }
}
