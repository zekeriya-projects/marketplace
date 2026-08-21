<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Support\TenantEntitlements;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureTenantEntitlement
{
    public function __construct(private TenantContext $context, private TenantEntitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless($this->entitlements->allows($this->context->get(), $feature), 403, 'Bu özellik mevcut abonelik paketinizde bulunmuyor.');

        return $next($request);
    }
}
