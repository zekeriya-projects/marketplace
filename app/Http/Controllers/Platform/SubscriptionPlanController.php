<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSubscriptionPlanRequest;
use App\Models\SubscriptionPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class SubscriptionPlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Platform/Plans', ['plans' => SubscriptionPlan::query()->with('entitlements')->withCount('subscriptions')->orderBy('sort_order')->get()]);
    }

    public function store(SaveSubscriptionPlanRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $data = $request->safe()->except('entitlements');
            $plan = SubscriptionPlan::query()->create($data);
            $plan->entitlements()->createMany($request->validated('entitlements', []));
        });

        return back()->with('success', 'Paket oluşturuldu.');
    }

    public function update(SaveSubscriptionPlanRequest $request, SubscriptionPlan $plan): RedirectResponse
    {
        DB::transaction(function () use ($request, $plan): void {
            $plan->update($request->safe()->except('entitlements'));
            $plan->entitlements()->delete();
            $plan->entitlements()->createMany($request->validated('entitlements', []));
        });

        return back()->with('success', 'Paket ve yetkileri güncellendi.');
    }

    public function destroy(SubscriptionPlan $plan): RedirectResponse
    {
        if ($plan->subscriptions()->exists()) {
            return back()->withErrors(['plan' => 'Aboneliği bulunan paket silinemez; pasif duruma alın.']);
        }
        $plan->delete();

        return back()->with('success', 'Paket silindi.');
    }
}
