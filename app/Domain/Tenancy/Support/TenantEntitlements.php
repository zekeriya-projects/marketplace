<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use App\Models\Tenant;

final class TenantEntitlements
{
    public function allows(Tenant $tenant, string $feature): bool
    {
        if (! $tenant->subscription()->exists()) {
            return true;
        }

        return $tenant->subscription()
            ->whereIn('status', ['trialing', 'active'])
            ->whereHas('plan.entitlements', fn ($query) => $query->where('feature_code', $feature)->where('enabled', true))
            ->exists();
    }

    public function limit(Tenant $tenant, string $feature): ?int
    {
        $value = $tenant->subscription?->plan?->entitlements->firstWhere('feature_code', $feature)?->limit;

        return is_int($value) ? $value : null;
    }
}
