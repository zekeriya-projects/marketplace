<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ChannelAccount;
use App\Models\ChannelReferenceMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class ChannelReferenceMappingController extends Controller
{
    public function store(Request $request, TenantContext $context): RedirectResponse
    {
        $tenant = $context->get();
        $validated = $request->validate([
            'channel_account_id' => ['required', 'uuid', Rule::exists('channel_accounts', 'id')->where('tenant_id', $tenant->id)],
            'reference_type' => ['required', 'in:brand,category'],
            'reference_id' => ['required', 'uuid'],
            'external_id' => ['required', 'string', 'max:150'],
            'external_name' => ['required', 'string', 'max:255'],
        ]);
        $reference = ($validated['reference_type'] === 'brand' ? Brand::query() : Category::query())
            ->where('tenant_id', $tenant->id)->findOrFail($validated['reference_id']);
        Gate::authorize('update', $reference);

        ChannelReferenceMapping::query()->updateOrCreate([
            'tenant_id' => $tenant->id,
            'channel_account_id' => $validated['channel_account_id'],
            'reference_type' => $validated['reference_type'],
            'reference_id' => $reference->id,
        ], [
            'external_id' => $validated['external_id'],
            'external_name' => $validated['external_name'],
        ]);

        return back()->with('success', 'Pazaryeri eşleştirmesi kaydedildi.');
    }

    public function destroy(ChannelReferenceMapping $mapping, TenantContext $context): RedirectResponse
    {
        abort_unless($mapping->tenant_id === $context->get()->id, 403);
        $account = ChannelAccount::query()->where('tenant_id', $context->get()->id)->findOrFail($mapping->channel_account_id);
        Gate::authorize('update', $account);
        $mapping->delete();

        return back()->with('success', 'Pazaryeri eşleştirmesi kaldırıldı.');
    }
}
