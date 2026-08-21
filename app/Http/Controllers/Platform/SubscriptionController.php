<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTenantSubscriptionRequest;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class SubscriptionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Platform/Subscriptions', [
            'subscriptions' => TenantSubscription::query()->with(['tenant:id,name,slug,status', 'plan:id,code,name'])->latest()->paginate(30),
            'plans' => SubscriptionPlan::query()->where('status', 'active')->orderBy('sort_order')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(UpdateTenantSubscriptionRequest $request, Tenant $tenant): RedirectResponse
    {
        DB::transaction(function () use ($request, $tenant): void {
            $data = $request->validated();
            $plan = SubscriptionPlan::query()->findOrFail($data['subscription_plan_id']);
            $data['cancelled_at'] = $data['status'] === 'cancelled' ? now() : null;
            $tenant->subscription()->updateOrCreate(['tenant_id' => $tenant->id], $data);
            $tenant->update(['subscription_plan' => $plan->code, 'subscription_status' => $data['status']]);
        });

        return back()->with('success', 'Abonelik güncellendi.');
    }
}
