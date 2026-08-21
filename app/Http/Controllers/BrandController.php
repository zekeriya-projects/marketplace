<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveBrandRequest;
use App\Models\Brand;
use App\Models\ChannelAccount;
use App\Models\ChannelReferenceMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class BrandController extends Controller
{
    public function index(TenantContext $context): Response
    {
        Gate::authorize('viewAny', Brand::class);

        $tenant = $context->get();
        $brands = $tenant->brands()->withCount('products')->orderBy('name')->paginate(20);

        return Inertia::render('Brands/Index', [
            'brands' => $brands,
            'channelAccounts' => ChannelAccount::query()->where('tenant_id', $tenant->id)->where('status', 'active')->with('channel:id,name,code')->get()->map(fn ($account): array => ['id' => $account->id, 'name' => $account->name, 'channel' => $account->channel->only(['name', 'code'])]),
            'mappings' => ChannelReferenceMapping::query()->where('tenant_id', $tenant->id)->where('reference_type', 'brand')->whereIn('reference_id', $brands->getCollection()->pluck('id'))->with('account.channel:id,name,code')->get()->map(fn ($mapping): array => [...$mapping->only(['id', 'reference_id', 'external_id', 'external_name']), 'account' => ['id' => $mapping->account->id, 'name' => $mapping->account->name, 'channel' => $mapping->account->channel->only(['name', 'code'])]]),
            'canManage' => Gate::allows('create', Brand::class),
        ]);
    }

    public function store(SaveBrandRequest $request, TenantContext $context): RedirectResponse
    {
        $context->get()->brands()->create($request->validated());

        return back()->with('success', 'Marka oluşturuldu.');
    }

    public function update(SaveBrandRequest $request, Brand $brand): RedirectResponse
    {
        $brand->update($request->validated());

        return back()->with('success', 'Marka güncellendi.');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        Gate::authorize('delete', $brand);
        abort_if($brand->products()->exists(), 422, 'Kullanılan marka silinemez.');
        $brand->delete();

        return back()->with('success', 'Marka silindi.');
    }
}
