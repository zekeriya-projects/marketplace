<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveTrendyolListingTemplateRequest;
use App\Models\Product;
use App\Models\TrendyolListingTemplate;
use Illuminate\Http\RedirectResponse;

final class TrendyolListingTemplateController extends Controller
{
    public function store(SaveTrendyolListingTemplateRequest $request, TenantContext $context): RedirectResponse
    {
        $data = $request->validated();
        $selected = collect($data['attributes'])->pluck('attributeId')->map(fn ($id) => (int) $id);
        abort_if(collect($data['required_attribute_ids'])->contains(fn ($id) => ! $selected->contains((int) $id)), 422, 'Zorunlu Trendyol özellikleri tamamlanmadan şablon kaydedilemez.');
        $context->get()->trendyolListingTemplates()->create([...$data, 'origin' => strtoupper($data['origin'])]);

        return back()->with('success', 'Trendyol yayın şablonu kaydedildi.');
    }

    public function destroy(TrendyolListingTemplate $template, TenantContext $context): RedirectResponse
    {
        abort_unless($template->tenant_id === $context->get()->id, 403);
        abort_unless(request()->user()?->can('create', Product::class), 403);
        $template->delete();

        return back()->with('success', 'Trendyol yayın şablonu silindi.');
    }
}
