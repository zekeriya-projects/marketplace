<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveCategoryRequest;
use App\Models\Category;
use App\Models\ChannelAccount;
use App\Models\ChannelReferenceMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CategoryController extends Controller
{
    public function index(TenantContext $context): Response
    {
        Gate::authorize('viewAny', Category::class);

        $tenant = $context->get();
        $categories = $tenant->categories()->with('parent:id,name')->withCount('products')->orderBy('name')->paginate(20);

        return Inertia::render('Categories/Index', [
            'categories' => $categories,
            'options' => $tenant->categories()->orderBy('name')->get(['id', 'name']),
            'channelAccounts' => ChannelAccount::query()->where('tenant_id', $tenant->id)->where('status', 'active')->with('channel:id,name,code')->get()->map(fn ($account): array => ['id' => $account->id, 'name' => $account->name, 'channel' => $account->channel->only(['name', 'code'])]),
            'mappings' => ChannelReferenceMapping::query()->where('tenant_id', $tenant->id)->where('reference_type', 'category')->whereIn('reference_id', $categories->getCollection()->pluck('id'))->with('account.channel:id,name,code')->get()->map(fn ($mapping): array => [...$mapping->only(['id', 'reference_id', 'external_id', 'external_name']), 'account' => ['id' => $mapping->account->id, 'name' => $mapping->account->name, 'channel' => $mapping->account->channel->only(['name', 'code'])]]),
            'canManage' => Gate::allows('create', Category::class),
        ]);
    }

    public function store(SaveCategoryRequest $request, TenantContext $context): RedirectResponse
    {
        $context->get()->categories()->create($request->validated());

        return back()->with('success', 'Kategori oluşturuldu.');
    }

    public function update(SaveCategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        return back()->with('success', 'Kategori güncellendi.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        Gate::authorize('delete', $category);
        abort_if($category->products()->exists() || $category->children()->exists(), 422, 'Kullanılan kategori silinemez.');
        $category->delete();

        return back()->with('success', 'Kategori silindi.');
    }
}
