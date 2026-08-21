<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Enums\ChannelListingStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ChannelListingController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): Response
    {
        Gate::authorize('viewAny', Product::class);
        $tenant = $context->get();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(ChannelListingStatus::class)],
            'account' => ['nullable', 'uuid', Rule::exists('channel_accounts', 'id')->where('tenant_id', $tenant->id)],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $base = ChannelListing::query()->where('tenant_id', $tenant->id);
        $counts = (clone $base)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $listings = $base
            ->with(['account.channel:id,name,code', 'product:id,name', 'variant.inventoryItems.warehouse'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['account'] ?? null, fn ($query, $account) => $query->where('channel_account_id', $account))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('external_sku', 'ilike', "%{$search}%")
                    ->orWhere('external_barcode', 'ilike', "%{$search}%")
                    ->orWhereHas('product', fn ($query) => $query->where('name', 'ilike', "%{$search}%"))
                    ->orWhereHas('variant', fn ($query) => $query->where('name', 'ilike', "%{$search}%"));
            }))
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(function (ChannelListing $listing): array {
                $available = $listing->variant?->inventoryItems
                    ->filter(fn ($item) => $item->warehouse?->is_active)
                    ->sum(fn ($item) => $item->quantity - $item->reserved_quantity) ?? 0;

                return [
                    ...$listing->only(['id', 'status', 'external_product_id', 'external_variant_id', 'external_sku', 'external_barcode', 'channel_price_amount', 'currency']),
                    'product' => $listing->product->only(['id', 'name']),
                    'variant' => $listing->variant?->only(['id', 'name', 'base_price_amount', 'currency']),
                    'account' => ['id' => $listing->account->id, 'name' => $listing->account->name, 'channel' => $listing->account->channel->only(['name', 'code'])],
                    'available' => (int) $available,
                    'published_at' => $listing->published_at?->toIso8601String(),
                    'last_synced_at' => $listing->last_synced_at?->toIso8601String(),
                ];
            });

        return Inertia::render('Marketplace/Index', [
            'listings' => $listings,
            'counts' => collect(ChannelListingStatus::cases())->mapWithKeys(fn ($status): array => [$status->value => (int) ($counts[$status->value] ?? 0)]),
            'accounts' => ChannelAccount::query()->where('tenant_id', $tenant->id)->with('channel:id,name')->orderBy('name')->get()->map(fn ($account): array => ['id' => $account->id, 'name' => $account->name, 'channel' => $account->channel->name]),
            'filters' => ['search' => $search, 'status' => $filters['status'] ?? '', 'account' => $filters['account'] ?? ''],
        ]);
    }
}
