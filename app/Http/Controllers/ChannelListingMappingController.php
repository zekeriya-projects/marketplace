<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class ChannelListingMappingController extends Controller
{
    public function store(Request $request, TenantContext $context): RedirectResponse
    {
        $tenant = $context->get();
        $validated = $request->validate([
            'channel_account_id' => ['required', 'uuid', Rule::exists('channel_accounts', 'id')->where('tenant_id', $tenant->id)],
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenant->id)],
            'product_variant_id' => ['required', 'uuid', Rule::exists('product_variants', 'id')->where('tenant_id', $tenant->id)],
            'external_product_id' => ['required', 'string', 'max:150'],
            'external_variant_id' => ['nullable', 'string', 'max:150'],
            'external_sku' => ['nullable', 'string', 'max:150'],
            'external_barcode' => ['nullable', 'string', 'max:150'],
        ]);
        $product = Product::query()->where('tenant_id', $tenant->id)->findOrFail($validated['product_id']);
        Gate::authorize('update', $product);
        $variant = ProductVariant::query()->where('tenant_id', $tenant->id)->where('product_id', $product->id)->findOrFail($validated['product_variant_id']);

        ChannelListing::query()->updateOrCreate([
            'tenant_id' => $tenant->id,
            'channel_account_id' => $validated['channel_account_id'],
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ], [
            'external_product_id' => $validated['external_product_id'],
            'external_variant_id' => ($validated['external_variant_id'] ?? null) ?: null,
            'external_sku' => ($validated['external_sku'] ?? null) ?: $variant->sku,
            'external_barcode' => ($validated['external_barcode'] ?? null) ?: $variant->barcode,
            'status' => ChannelListingStatus::Active,
            'last_synced_at' => now(),
        ]);

        return back()->with('success', 'Ürün pazaryeri kaydıyla eşleştirildi.');
    }
}
