<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\ManageTenantMembership;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Http\Requests\StoreTenantMemberRequest;
use App\Http\Requests\UpdateTenantMemberRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class TenantMemberController extends Controller
{
    public function store(StoreTenantMemberRequest $request, Tenant $tenant, ManageTenantMembership $memberships): RedirectResponse
    {
        $member = User::query()->where('email', $request->string('email')->toString())->firstOrFail();
        $memberships->add($request->user(), $tenant, $member, TenantRole::from($request->string('role')->toString()));

        return back()->with('success', 'Member added.');
    }

    public function update(UpdateTenantMemberRequest $request, Tenant $tenant, User $member, ManageTenantMembership $memberships): RedirectResponse
    {
        $memberships->update($request->user(), $tenant, $member, TenantRole::from($request->string('role')->toString()));

        return back()->with('success', 'Member role updated.');
    }

    public function destroy(Request $request, Tenant $tenant, User $member, ManageTenantMembership $memberships): RedirectResponse
    {
        Gate::authorize('manageMembers', $tenant);
        $memberships->remove($request->user(), $tenant, $member);

        return back()->with('success', 'Member removed.');
    }
}
