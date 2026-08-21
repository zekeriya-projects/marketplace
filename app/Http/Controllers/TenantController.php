<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class TenantController extends Controller
{
    public function show(Tenant $tenant, TenantContext $context): Response
    {
        Gate::authorize('view', $tenant);
        abort_unless($context->get()->is($tenant), 404);

        $trialDays = 14;
        $trialEndsAt = $tenant->created_at->copy()->addDays($trialDays);
        $remainingDays = max(0, (int) Carbon::now()->startOfDay()->diffInDays($trialEndsAt->startOfDay(), false));

        return Inertia::render('Settings/Organization', [
            'tenant' => $tenant->only([
                'id', 'name', 'slug', 'status', 'subscription_plan', 'subscription_status',
                'legal_title', 'contact_first_name', 'contact_last_name', 'mobile_phone',
                'company_email', 'website', 'company_phone', 'province', 'district', 'address',
                'company_type', 'tax_office', 'national_id',
            ]),
            'profile' => [
                ...request()->user()->only(['name', 'email']),
                'registered_at' => request()->user()->created_at->format('d.m.Y'),
            ],
            'trial' => [
                'ends_at' => $trialEndsAt->format('d.m.Y'),
                'total_days' => $trialDays,
                'remaining_days' => $remainingDays,
                'remaining_percent' => (int) round(($remainingDays / $trialDays) * 100),
            ],
            'members' => $tenant->users()
                ->orderBy('name')
                ->get(['users.id', 'users.name', 'users.email'])
                ->map(fn ($user): array => [
                    ...$user->only(['id', 'name', 'email']),
                    'role' => $user->pivot->role,
                ]),
            'canUpdate' => Gate::allows('update', $tenant),
            'canManageMembers' => Gate::allows('manageMembers', $tenant),
            'currentRole' => request()->user()->roleFor($tenant)?->value,
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): RedirectResponse
    {
        $tenant->update($request->validated());

        return back()->with('success', 'Organization updated.');
    }

    public function switch(Request $request, Tenant $tenant): RedirectResponse
    {
        Gate::authorize('switch', $tenant);
        $request->user()->forceFill(['active_tenant_id' => $tenant->getKey()])->save();

        return redirect()->route('dashboard');
    }
}
