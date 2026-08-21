<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManagePlatformTenantMembership
{
    public function add(Tenant $tenant, User $member, TenantRole $role): void
    {
        if ($member->is_platform_admin) {
            throw ValidationException::withMessages(['email' => 'Platform yöneticileri organizasyon üyesi olarak eklenemez.']);
        }
        if ($member->belongsToTenant($tenant)) {
            throw ValidationException::withMessages(['email' => 'Kullanıcı zaten bu organizasyonun üyesi.']);
        }
        $tenant->users()->attach($member, ['role' => $role->value]);
        if ($member->active_tenant_id === null) {
            $member->forceFill(['active_tenant_id' => $tenant->id])->save();
        }
    }

    public function update(Tenant $tenant, User $member, TenantRole $role): void
    {
        DB::transaction(function () use ($tenant, $member, $role): void {
            $current = $this->role($tenant, $member);
            $this->ensureOwnerRemains($tenant, $current, $role);
            $tenant->users()->updateExistingPivot($member->id, ['role' => $role->value]);
        });
    }

    public function remove(Tenant $tenant, User $member): void
    {
        DB::transaction(function () use ($tenant, $member): void {
            $current = $this->role($tenant, $member);
            $this->ensureOwnerRemains($tenant, $current, null);
            $tenant->users()->detach($member);
            if ($member->active_tenant_id === $tenant->id) {
                $replacement = $member->tenants()->oldest('tenants.created_at')->first();
                $member->forceFill(['active_tenant_id' => $replacement?->id])->save();
            }
        });
    }

    private function role(Tenant $tenant, User $member): TenantRole
    {
        return $member->roleFor($tenant) ?? throw ValidationException::withMessages(['member' => 'Kullanıcı bu organizasyonun üyesi değil.']);
    }

    private function ensureOwnerRemains(Tenant $tenant, TenantRole $current, ?TenantRole $next): void
    {
        if ($current !== TenantRole::Owner || $next === TenantRole::Owner) {
            return;
        }
        $owners = DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('role', 'owner')->lockForUpdate()->get()->count();
        if ($owners <= 1) {
            throw ValidationException::withMessages(['role' => 'Organizasyonda en az bir owner kalmalıdır.']);
        }
    }
}
