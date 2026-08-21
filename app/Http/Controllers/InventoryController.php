<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Actions\AdjustInventory;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\AdjustInventoryRequest;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class InventoryController extends Controller
{
    public function index(Request $request, TenantContext $context): Response
    {
        Gate::authorize('viewAny', Warehouse::class);
        $tenant = $context->get();
        $warehouses = $tenant->warehouses()->orderByDesc('is_default')->orderBy('name')->get();
        $warehouse = $warehouses->firstWhere('id', $request->query('warehouse')) ?? $warehouses->first();
        $search = trim((string) $request->query('search', ''));

        $variants = $warehouse === null ? null : ProductVariant::query()
            ->where('tenant_id', $tenant->getKey())
            ->with('product:id,name')
            ->with(['inventoryItems' => fn ($query) => $query->where('warehouse_id', $warehouse->id)])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku', 'ilike', "%{$search}%")
                    ->orWhere('barcode', 'ilike', "%{$search}%")
                    ->orWhereHas('product', fn ($query) => $query->where('name', 'ilike', "%{$search}%"));
            }))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(function (ProductVariant $variant): array {
                $item = $variant->inventoryItems->first();

                return [
                    ...$variant->only(['id', 'name', 'sku', 'barcode']),
                    'product_name' => $variant->product->name,
                    'quantity' => $item?->quantity ?? 0,
                    'reserved_quantity' => $item?->reserved_quantity ?? 0,
                    'available_quantity' => $item?->availableQuantity() ?? 0,
                ];
            });

        return Inertia::render('Inventory/Index', [
            'warehouses' => $warehouses->map->only(['id', 'name', 'code', 'is_default', 'is_active']),
            'activeWarehouseId' => $warehouse?->id,
            'variants' => $variants,
            'filters' => ['search' => $search],
            'canManage' => Gate::allows('create', Warehouse::class),
        ]);
    }

    public function show(Request $request, ProductVariant $variant, TenantContext $context): Response
    {
        $variant->load('product');
        Gate::authorize('view', $variant->product);
        $warehouses = $context->get()->warehouses()->orderByDesc('is_default')->orderBy('name')->get();
        $warehouse = $warehouses->firstWhere('id', $request->query('warehouse')) ?? $warehouses->first();
        abort_if($warehouse === null, 404, 'Create a warehouse before viewing inventory.');
        Gate::authorize('view', $warehouse);

        $item = $variant->inventoryItems()->where('warehouse_id', $warehouse->id)->first();
        $movements = $variant->inventoryMovements()
            ->where('tenant_id', $context->get()->getKey())
            ->where('warehouse_id', $warehouse->id)
            ->with('createdBy:id,name')
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn ($movement): array => [
                ...$movement->only(['id', 'type', 'quantity_delta', 'quantity_before', 'quantity_after', 'note']),
                'created_at' => $movement->created_at->toIso8601String(),
                'created_by' => $movement->createdBy?->name,
            ]);

        return Inertia::render('Inventory/Show', [
            'variant' => [
                ...$variant->only(['id', 'name', 'sku', 'barcode']),
                'product_name' => $variant->product->name,
            ],
            'warehouse' => $warehouse->only(['id', 'name', 'code']),
            'warehouses' => $warehouses->map->only(['id', 'name', 'code']),
            'stock' => [
                'quantity' => $item?->quantity ?? 0,
                'reserved_quantity' => $item?->reserved_quantity ?? 0,
                'available_quantity' => $item?->availableQuantity() ?? 0,
            ],
            'movements' => $movements,
            'canAdjust' => Gate::allows('adjust', $warehouse),
        ]);
    }

    public function adjust(AdjustInventoryRequest $request, ProductVariant $variant, Warehouse $warehouse, TenantContext $context, AdjustInventory $action): RedirectResponse
    {
        $validated = $request->validated();
        $action->execute($context->get(), $warehouse, $variant, (int) $validated['quantity_delta'], $validated['note'], $request->user());

        return redirect()->route('inventory.show', ['variant' => $variant, 'warehouse' => $warehouse->id])
            ->with('success', 'Inventory adjusted.');
    }
}
