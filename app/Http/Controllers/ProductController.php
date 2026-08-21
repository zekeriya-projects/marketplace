<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Support\MinorUnits;
use App\Domain\Channels\Actions\BulkPublishTrendyolProducts;
use App\Domain\Channels\Actions\QueueWooCommerceProductSync;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\BulkPublishProductsRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\ChannelAccount;
use App\Models\Product;
use App\Models\TrendyolListingTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

final class ProductController extends Controller
{
    public function index(Request $request, TenantContext $context): Response
    {
        Gate::authorize('viewAny', Product::class);
        $search = trim((string) $request->query('search', ''));

        $products = Product::query()
            ->whereBelongsTo($context->get())
            ->with([
                'category:id,name',
                'brandReference:id,name',
                'images',
                'variants.inventoryItems.warehouse',
                'channelListings.account.channel:id,name,code',
            ])
            ->withCount('variants')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('name', 'ilike', "%{$search}%")
                    ->orWhere('brand', 'ilike', "%{$search}%")
                    ->orWhereHas('variants', fn ($query) => $query
                        ->where('sku', 'ilike', "%{$search}%")
                        ->orWhere('barcode', 'ilike', "%{$search}%"));
            }))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(function (Product $product): array {
                $variant = $product->variants->first();
                $available = $product->variants->flatMap(fn ($variant) => $variant->inventoryItems)
                    ->filter(fn ($item) => $item->warehouse?->is_active)
                    ->sum(fn ($item) => $item->quantity - $item->reserved_quantity);
                $image = $product->images->first();

                return [
                    ...$product->only(['id', 'name', 'brand', 'status', 'vat_rate']),
                    'brand' => $product->brandReference?->name ?? $product->brand,
                    'category' => $product->category?->name,
                    'variants_count' => $product->variants_count,
                    'product_type' => $product->variants_count > 1 ? 'Varyantlı' : 'Basit',
                    'sku' => $variant?->sku,
                    'price' => $variant === null ? null : MinorUnits::toDecimal($variant->base_price_amount),
                    'currency' => $variant?->currency,
                    'available' => (int) $available,
                    'image_url' => $image === null ? null : Storage::disk($image->disk)->url($image->path),
                    'channels' => $product->channelListings->map(fn ($listing) => $listing->account->channel->only(['name', 'code']))->unique('code')->values(),
                    'variants' => $product->variants->map->only(['id', 'name', 'sku', 'barcode'])->values(),
                ];
            });

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => ['search' => $search],
            'canCreate' => Gate::allows('create', Product::class),
            'channelAccounts' => ChannelAccount::query()->where('tenant_id', $context->get()->id)->where('status', 'active')->with('channel:id,name,code')->orderBy('name')->get()->map(fn ($account): array => ['id' => $account->id, 'name' => $account->name, 'channel' => $account->channel->only(['name', 'code'])]),
            'trendyolTemplates' => TrendyolListingTemplate::query()->where('tenant_id', $context->get()->id)->with('category:id,name')->orderBy('name')->get()->map(fn ($template): array => [...$template->only(['id', 'name', 'category_id']), 'category' => $template->category?->name]),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        Gate::authorize('create', Product::class);

        return Inertia::render('Products/Create', $this->references($context));
    }

    public function store(StoreProductRequest $request, TenantContext $context, SaveProduct $action, QueueWooCommerceProductSync $sync): RedirectResponse
    {
        $product = $action->create($context->get(), $request->validated());
        $this->storeImages($request, $product);
        $sync->execute($product);

        return redirect()->route('products.show', $product)->with('success', 'Product created.');
    }

    public function show(Product $product): Response
    {
        Gate::authorize('view', $product);
        $product->load([
            'variants' => fn ($query) => $query->orderBy('name'),
            'category:id,name',
            'brandReference:id,name',
            'images',
            'channelListings' => fn ($query) => $query->with(['account.channel:id,name,code', 'variant:id,name'])->latest(),
        ]);

        return Inertia::render('Products/Show', [
            'product' => $this->serializeProduct($product),
            'canUpdate' => Gate::allows('update', $product),
            'trendyolAccounts' => ChannelAccount::query()->where('tenant_id', $product->tenant_id)->where('status', 'active')->whereHas('channel', fn ($query) => $query->where('code', 'trendyol'))->get(['id', 'name']),
            'channelListings' => $product->channelListings->map(fn ($listing): array => [
                ...$listing->only(['id', 'status', 'published_at', 'last_synced_at']),
                'account' => $listing->account->name,
                'channel' => $listing->account->channel->name,
                'variant' => $listing->variant->name,
            ]),
        ]);
    }

    public function edit(Product $product, TenantContext $context): Response
    {
        Gate::authorize('update', $product);
        $product->load(['variants' => fn ($query) => $query->orderBy('name')]);

        return Inertia::render('Products/Edit', ['product' => $this->serializeProduct($product), ...$this->references($context)]);
    }

    public function update(UpdateProductRequest $request, Product $product, TenantContext $context, SaveProduct $action, QueueWooCommerceProductSync $sync): RedirectResponse
    {
        $action->update($context->get(), $product, $request->validated());
        $this->storeImages($request, $product);
        $sync->execute($product->fresh());

        return redirect()->route('products.show', $product)->with('success', 'Product updated.');
    }

    public function publishWooCommerce(Product $product, ChannelAccount $account, QueueWooCommerceProductSync $sync): RedirectResponse
    {
        Gate::authorize('update', $product);
        Gate::authorize('update', $account);
        abort_unless($account->tenant_id === $product->tenant_id, 422, 'Ürün bu WooCommerce hesabına gönderilemedi.');
        $outcome = $sync->executeForAccount($product, $account);
        abort_if($outcome->status->value === 'invalid', 422, 'Ürün bu WooCommerce hesabına gönderilemedi.');

        return back()->with('success', $outcome->queued() ? 'WooCommerce ürün yayını kuyruğa alındı.' : 'WooCommerce ürün yayını zaten kuyrukta veya dış senkronizasyonu kapalı.');
    }

    public function bulkPublish(BulkPublishProductsRequest $request, TenantContext $context, QueueWooCommerceProductSync $wooCommerce, BulkPublishTrendyolProducts $trendyol): RedirectResponse
    {
        $tenant = $context->get();
        $validated = $request->validated();
        $productQuery = Product::query()->where('tenant_id', $tenant->id);
        if ($validated['select_all']) {
            $search = trim((string) ($validated['search'] ?? ''));
            $productQuery->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('name', 'ilike', "%{$search}%")
                    ->orWhere('brand', 'ilike', "%{$search}%")
                    ->orWhereHas('variants', fn ($query) => $query
                        ->where('sku', 'ilike', "%{$search}%")
                        ->orWhere('barcode', 'ilike', "%{$search}%"));
            }));
            abort_if((clone $productQuery)->count() > 500, 422, 'Tek toplu işlemde en fazla 500 ürün gönderilebilir. Filtre kullanarak ürünleri gruplara ayırın.');
        } else {
            $productQuery->whereIn('id', $validated['product_ids']);
        }
        $products = $productQuery
            ->with(['variants', 'images', 'channelListings'])
            ->get();
        abort_if($products->isEmpty(), 404);
        if (! $validated['select_all']) {
            abort_unless($products->count() === count($validated['product_ids']), 404);
        }

        $accounts = ChannelAccount::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereIn('id', $validated['channel_account_ids'])
            ->with('channel:id,code,name')
            ->get();
        abort_unless($accounts->count() === count($validated['channel_account_ids']), 404);
        $accounts->each(fn (ChannelAccount $account) => Gate::authorize('update', $account));

        $queued = 0;
        $alreadyLinked = 0;
        $requiresSetup = 0;
        $bulkResult = ['queued' => 0, 'already_linked' => 0, 'blocked' => 0, 'succeeded' => 0, 'failed' => 0];
        $template = isset($validated['trendyol_template_id']) ? TrendyolListingTemplate::query()->where('tenant_id', $tenant->id)->findOrFail($validated['trendyol_template_id']) : null;

        foreach ($accounts as $account) {
            foreach ($products as $product) {
                if ($account->channel->code === 'woocommerce') {
                    $outcome = $wooCommerce->executeForAccount($product, $account);
                    $queued += $outcome->queued() ? 1 : 0;
                    $alreadyLinked += $outcome->status->value === 'already_active' ? 1 : 0;

                    continue;
                }

                if ($account->channel->code === 'trendyol') {
                    continue;
                }
            }
            if ($account->channel->code === 'trendyol') {
                if ($template === null) {
                    $requiresSetup += $products->count();
                } else {
                    $outcome = $trendyol->execute($account, $template, $products);
                    foreach ($bulkResult as $key => $value) {
                        $bulkResult[$key] += $outcome[$key];
                    }
                    $queued += $outcome['queued'];
                    $alreadyLinked += $outcome['already_linked'];
                    $requiresSetup += $outcome['blocked'];
                }
            }
        }

        $message = "{$queued} ürün yayını kuyruğa alındı.";
        if ($alreadyLinked > 0) {
            $message .= " {$alreadyLinked} ürün zaten ilgili pazaryerine bağlı.";
        }
        if ($requiresSetup > 0) {
            $message .= " {$requiresSetup} Trendyol ürünü için kategori ve özellik eşleştirmesi gerekiyor; bunları ürün satırındaki + ile tamamlayın.";
        }

        return back()->with('success', $message)->with('bulk_publish_result', $bulkResult);
    }

    private function serializeProduct(Product $product): array
    {
        $primaryImage = $product->relationLoaded('images') ? $product->images->first() : null;
        $imageUrl = $primaryImage === null ? null : Storage::disk($primaryImage->disk)->url($primaryImage->path);
        if ($imageUrl !== null && ! str_starts_with($imageUrl, 'http')) {
            $imageUrl = rtrim((string) config('app.url'), '/').$imageUrl;
        }

        return [
            ...$product->only(['id', 'name', 'brand', 'brand_id', 'category_id', 'description', 'status', 'short_name', 'invoice_name', 'custom_code_1', 'custom_code_2', 'desi', 'desi_2', 'vat_rate', 'excise_tax_rate', 'communication_tax_rate', 'disable_external_sync', 'vat_exemption_code', 'expiration_date']),
            'compare_at_price' => $product->compare_at_price_amount === null ? null : MinorUnits::toDecimal($product->compare_at_price_amount),
            'purchase_price' => $product->purchase_price_amount === null ? null : MinorUnits::toDecimal($product->purchase_price_amount),
            'category_name' => $product->category?->name,
            'brand_name' => $product->brandReference?->name ?? $product->brand,
            'primary_image_url' => is_string($imageUrl) ? $imageUrl : null,
            'variants' => $product->variants->map(fn ($variant): array => [
                ...$variant->only(['id', 'name', 'sku', 'barcode', 'currency', 'status', 'variant_template_id', 'option_values']),
                'base_price' => MinorUnits::toDecimal($variant->base_price_amount),
            ])->all(),
        ];
    }

    private function references(TenantContext $context): array
    {
        $tenant = $context->get();

        return [
            'categories' => $tenant->categories()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'brands' => $tenant->brands()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'variantTemplates' => $tenant->variantTemplates()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'options']),
        ];
    }

    private function storeImages(Request $request, Product $product): void
    {
        foreach ($request->file('images', []) as $index => $image) {
            $path = $image->store("products/{$product->tenant_id}/{$product->id}", 'public');
            $product->images()->create(['tenant_id' => $product->tenant_id, 'disk' => 'public', 'path' => $path, 'alt_text' => $product->name, 'sort_order' => $product->images()->max('sort_order') + $index + 1]);
        }
    }
}
