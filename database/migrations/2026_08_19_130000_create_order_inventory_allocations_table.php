<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_inventory_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_variant_id');
            $table->unsignedInteger('sold_quantity');
            $table->unsignedInteger('cancelled_quantity')->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'])->references(['tenant_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->unique(['order_id', 'warehouse_id', 'product_variant_id'], 'order_inventory_allocations_unique');
            $table->index(['tenant_id', 'order_id']);
        });

        DB::statement('ALTER TABLE order_inventory_allocations ADD CONSTRAINT order_inventory_allocations_sold_positive CHECK (sold_quantity > 0)');
        DB::statement('ALTER TABLE order_inventory_allocations ADD CONSTRAINT order_inventory_allocations_compensation_bounded CHECK (cancelled_quantity + returned_quantity <= sold_quantity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_inventory_allocations');
    }
};
