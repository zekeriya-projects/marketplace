<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domain\Tenancy\Actions\ManagePlatformTenantMembership;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlatformMemberRequest;
use App\Http\Requests\UpdatePlatformMemberRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class TenantMemberController extends Controller
{
    public function store(StorePlatformMemberRequest $request, Tenant $tenant, ManagePlatformTenantMembership $memberships): RedirectResponse
    {
        $member = User::query()->where('email', $request->string('email'))->sole();
        $memberships->add($tenant, $member, TenantRole::from($request->string('role')->toString()));

        return back()->with('success', 'Üye organizasyona eklendi.');
    }

    public function update(UpdatePlatformMemberRequest $request, Tenant $tenant, User $member, ManagePlatformTenantMembership $memberships): RedirectResponse
    {
        $memberships->update($tenant, $member, TenantRole::from($request->string('role')->toString()));

        return back()->with('success', 'Üye rolü güncellendi.');
    }

    public function destroy(Tenant $tenant, User $member, ManagePlatformTenantMembership $memberships): RedirectResponse
    {
        $memberships->remove($tenant, $member);

        return back()->with('success', 'Üye organizasyondan çıkarıldı.');
    }
}
