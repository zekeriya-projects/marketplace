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
        Schema::table('orders', fn (Blueprint $table) => $table->index(['tenant_id', 'currency', 'ordered_at', 'channel_account_id'], 'orders_tenant_currency_ordered_account_index'));
        Schema::table('order_items', fn (Blueprint $table) => $table->index(['order_id', 'product_variant_id'], 'order_items_order_variant_index'));
        DB::statement('CREATE INDEX inventory_items_tenant_available_index ON inventory_items (tenant_id, (quantity - reserved_quantity))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inventory_items_tenant_available_index');
        Schema::table('order_items', fn (Blueprint $table) => $table->dropIndex('order_items_order_variant_index'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropIndex('orders_tenant_currency_ordered_account_index'));
    }
};
