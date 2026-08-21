<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO order_inventory_allocations (
                id, tenant_id, order_id, warehouse_id, product_variant_id,
                sold_quantity, cancelled_quantity, returned_quantity, created_at, updated_at
            )
            SELECT
                uuidv7(), orders.tenant_id, orders.id, warehouses.id, order_items.product_variant_id,
                SUM(order_items.quantity)::integer, 0, 0, NOW(), NOW()
            FROM orders
            INNER JOIN order_items
                ON order_items.tenant_id = orders.tenant_id
                AND order_items.order_id = orders.id
                AND order_items.mapping_status = 'mapped'
                AND order_items.product_variant_id IS NOT NULL
            INNER JOIN warehouses
                ON warehouses.tenant_id = orders.tenant_id
                AND warehouses.is_default = TRUE
                AND warehouses.is_active = TRUE
            WHERE orders.inventory_applied_at IS NOT NULL
            GROUP BY orders.tenant_id, orders.id, warehouses.id, order_items.product_variant_id
            ON CONFLICT (order_id, warehouse_id, product_variant_id) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        // The preceding table migration owns rollback of these rows.
    }
};
