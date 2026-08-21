<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Support\MinorUnits;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class VariantController extends Controller
{
    public function index(Request $request, TenantContext $context): Response
    {
        Gate::authorize('viewAny', Product::class);
        $search = trim((string) $request->query('search', ''));
        $variants = ProductVariant::query()->where('tenant_id', $context->get()->id)->with('product:id,name')->withCount('channelListings')
            ->withSum(['inventoryItems as quantity_sum' => fn ($q) => $q->whereHas('warehouse', fn ($w) => $w->where('is_active', true))], 'quantity')
            ->withSum(['inventoryItems as reserved_sum' => fn ($q) => $q->whereHas('warehouse', fn ($w) => $w->where('is_active', true))], 'reserved_quantity')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%")->orWhere('barcode', 'ilike', "%{$search}%")->orWhereHas('product', fn ($p) => $p->where('name', 'ilike', "%{$search}%"))))
            ->latest()->paginate(25)->withQueryString()->through(fn ($v) => [...$v->only(['id', 'name', 'sku', 'barcode', 'currency', 'status']), 'product' => $v->product->only(['id', 'name']), 'price' => MinorUnits::toDecimal($v->base_price_amount), 'available' => (int) ($v->quantity_sum ?? 0) - (int) ($v->reserved_sum ?? 0), 'listings_count' => $v->channel_listings_count]);

        return Inertia::render('Variants/Index', ['variants' => $variants, 'filters' => ['search' => $search]]);
    }
}
