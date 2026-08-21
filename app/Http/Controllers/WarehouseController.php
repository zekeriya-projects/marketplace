<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Actions\CreateWarehouse;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\StoreWarehouseRequest;
use Illuminate\Http\RedirectResponse;

final class WarehouseController extends Controller
{
    public function store(StoreWarehouseRequest $request, TenantContext $context, CreateWarehouse $action): RedirectResponse
    {
        $warehouse = $action->execute($context->get(), $request->validated());

        return redirect()->route('inventory.index', ['warehouse' => $warehouse->id])->with('success', 'Warehouse created.');
    }
}
