<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

final class CreateWarehouse
{
    public function execute(Tenant $tenant, array $attributes): Warehouse
    {
        return DB::transaction(function () use ($tenant, $attributes): Warehouse {
            $makeDefault = (bool) ($attributes['is_default'] ?? false) || ! $tenant->warehouses()->exists();

            if ($makeDefault) {
                $tenant->warehouses()->where('is_default', true)->update(['is_default' => false]);
            }

            return $tenant->warehouses()->create([
                ...$attributes,
                'code' => strtoupper($attributes['code']),
                'is_default' => $makeDefault,
                'is_active' => true,
            ]);
        });
    }
}
