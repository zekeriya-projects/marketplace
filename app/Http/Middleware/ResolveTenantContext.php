<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolveTenantContext
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        if ($user->is_platform_admin) {
            return redirect()->route('platform.dashboard');
        }

        $tenant = $user->activeTenant;

        if ($tenant === null || ! $user->belongsToTenant($tenant)) {
            $tenant = $user->tenants()->orderBy('tenants.created_at')->first();
            abort_if($tenant === null, 403, 'No organization membership is available.');
            $user->forceFill(['active_tenant_id' => $tenant->getKey()])->save();
        }

        abort_unless($tenant->status === TenantStatus::Active, 403, 'The active organization is disabled.');
        $this->context->set($tenant);

        return $next($request);
    }
}
