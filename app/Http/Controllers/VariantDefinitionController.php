<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveVariantDefinitionRequest;
use App\Models\VariantDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class VariantDefinitionController extends Controller
{
    public function index(TenantContext $context): Response
    {
        Gate::authorize('viewAny', VariantDefinition::class);

        return Inertia::render('VariantDefinitions/Index', ['definitions' => $context->get()->variantDefinitions()->orderBy('name')->paginate(20), 'canManage' => Gate::allows('create', VariantDefinition::class)]);
    }

    public function store(SaveVariantDefinitionRequest $request, TenantContext $context): RedirectResponse
    {
        $context->get()->variantDefinitions()->create($request->validated());

        return back()->with('success', 'Varyant tanımı oluşturuldu.');
    }

    public function update(SaveVariantDefinitionRequest $request, VariantDefinition $variantDefinition): RedirectResponse
    {
        $variantDefinition->update($request->validated());

        return back()->with('success', 'Varyant tanımı güncellendi.');
    }

    public function destroy(VariantDefinition $variantDefinition): RedirectResponse
    {
        Gate::authorize('delete', $variantDefinition);
        $variantDefinition->delete();

        return back()->with('success', 'Varyant tanımı silindi.');
    }
}
