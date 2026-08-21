<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user?->only(['id', 'name', 'email', 'is_platform_admin']),
                'activeTenantId' => $user?->active_tenant_id,
                'subscription' => fn (): ?array => $user?->activeTenant?->subscription?->loadMissing('plan.entitlements') ? [
                    'status' => $user->activeTenant->subscription->status,
                    'plan' => $user->activeTenant->subscription->plan->only(['code', 'name']),
                    'entitlements' => $user->activeTenant->subscription->plan->entitlements->where('enabled', true)->mapWithKeys(fn ($item) => [$item->feature_code => $item->limit])->all(),
                ] : null,
                'tenants' => fn (): array => $user?->tenants()
                    ->orderBy('name')
                    ->get(['tenants.id', 'tenants.name'])
                    ->map(fn ($tenant): array => [
                        ...$tenant->only(['id', 'name']),
                        'role' => $tenant->pivot->role,
                    ])
                    ->all() ?? [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'bulkPublishResult' => fn () => $request->session()->get('bulk_publish_result'),
            ],
        ];
    }
}
