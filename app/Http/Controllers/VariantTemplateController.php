<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveVariantTemplateRequest;
use App\Models\VariantTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class VariantTemplateController extends Controller
{
    public function index(TenantContext $context): Response
    {
        Gate::authorize('viewAny', VariantTemplate::class);

        return Inertia::render('VariantTemplates/Index', ['templates' => $context->get()->variantTemplates()->withCount('variants')->orderBy('name')->paginate(20), 'definitions' => $context->get()->variantDefinitions()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'values']), 'canManage' => Gate::allows('create', VariantTemplate::class)]);
    }

    public function store(SaveVariantTemplateRequest $request, TenantContext $context): RedirectResponse
    {
        $context->get()->variantTemplates()->create($this->values($request, $context));

        return back()->with('success', 'Varyant şablonu oluşturuldu.');
    }

    public function update(SaveVariantTemplateRequest $request, VariantTemplate $variantTemplate, TenantContext $context): RedirectResponse
    {
        $variantTemplate->update($this->values($request, $context));

        return back()->with('success', 'Varyant şablonu güncellendi.');
    }

    private function values(SaveVariantTemplateRequest $request, TenantContext $context): array
    {
        $validated = $request->validated();
        $definitions = $context->get()->variantDefinitions()->whereKey($validated['definition_ids'])->where('status', 'active')->get()->keyBy('id');

        return ['name' => $validated['name'], 'description' => $validated['description'] ?? null, 'status' => $validated['status'], 'options' => collect($validated['definition_ids'])->map(fn ($id) => ['definition_id' => $id, 'name' => $definitions[$id]->name, 'values' => $definitions[$id]->values])->all()];
    }

    public function destroy(VariantTemplate $variantTemplate): RedirectResponse
    {
        Gate::authorize('delete', $variantTemplate);
        abort_if($variantTemplate->variants()->exists(), 422, 'Kullanılan şablon silinemez.');
        $variantTemplate->delete();

        return back()->with('success', 'Varyant şablonu silindi.');
    }
}
