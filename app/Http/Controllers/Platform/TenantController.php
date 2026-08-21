<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domain\Tenancy\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlatformTenantRequest;
use App\Http\Requests\UpdatePlatformTenantRequest;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class TenantController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Platform/Tenants', [
            'plans' => SubscriptionPlan::query()->where('status', 'active')->orderBy('sort_order')->get(['code', 'name']),
            'tenants' => Tenant::query()
                ->withCount(['users', 'products', 'channelAccounts', 'orders'])
                ->latest()
                ->paginate(25),
        ]);
    }

    public function store(StorePlatformTenantRequest $request): RedirectResponse
    {
        $tenant = DB::transaction(function () use ($request): Tenant {
            $data = $request->safe()->except('owner_email');
            $tenant = Tenant::query()->create([...$data, 'status' => 'active']);
            $plan = SubscriptionPlan::query()->where('code', $data['subscription_plan'])->sole();
            $tenant->subscription()->create([
                'subscription_plan_id' => $plan->id, 'status' => $data['subscription_status'], 'billing_cycle' => 'monthly',
                'starts_at' => now(), 'trial_ends_at' => $data['subscription_status'] === 'trialing' ? now()->addDays($plan->trial_days) : null,
            ]);
            $owner = User::query()->where('email', $request->string('owner_email'))->sole();
            abort_if($owner->is_platform_admin, 422, 'Platform yöneticisi organizasyon owner olarak atanamaz.');
            $tenant->users()->attach($owner, ['role' => TenantRole::Owner->value]);
            if ($owner->active_tenant_id === null) {
                $owner->forceFill(['active_tenant_id' => $tenant->id])->save();
            }

            return $tenant;
        });

        return redirect()->route('platform.tenants.show', $tenant)->with('success', 'Organizasyon oluşturuldu.');
    }

    public function show(Tenant $tenant): Response
    {
        return Inertia::render('Platform/TenantDetail', [
            'tenant' => $tenant->load(['subscription.plan.entitlements'])->loadCount(['products', 'channelAccounts', 'orders']),
            'plans' => SubscriptionPlan::query()->where('status', 'active')->orderBy('sort_order')->get(['id', 'code', 'name']),
            'members' => $tenant->users()->orderBy('name')->get(['users.id', 'users.name', 'users.email'])->map(fn ($member) => [...$member->only(['id', 'name', 'email']), 'role' => $member->pivot->role]),
        ]);
    }

    public function update(UpdatePlatformTenantRequest $request, Tenant $tenant): RedirectResponse
    {
        DB::transaction(function () use ($request, $tenant): void {
            $tenant->update($request->validated());
            $plan = SubscriptionPlan::query()->where('code', $request->string('subscription_plan'))->sole();
            $tenant->subscription()->updateOrCreate(['tenant_id' => $tenant->id], [
                'subscription_plan_id' => $plan->id, 'status' => $request->string('subscription_status'),
                'billing_cycle' => $tenant->subscription?->billing_cycle ?? 'monthly', 'starts_at' => $tenant->subscription?->starts_at ?? now(),
            ]);
        });

        return back()->with('success', 'Organizasyon durumu güncellendi.');
    }
}
