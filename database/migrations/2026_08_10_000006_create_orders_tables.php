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
        Schema::table('channel_listings', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'channel_listings_tenant_id_id_unique');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_account_id');
            $table->string('external_order_id');
            $table->string('external_order_number')->nullable();
            $table->string('status', 20);
            $table->string('external_status')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_amount')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('shipping_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->jsonb('customer_snapshot');
            $table->jsonb('shipping_address_snapshot');
            $table->jsonb('billing_address_snapshot')->nullable();
            $table->timestampTz('ordered_at');
            $table->timestampTz('imported_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'channel_account_id'])->references(['tenant_id', 'id'])->on('channel_accounts')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['channel_account_id', 'external_order_id']);
            $table->index(['tenant_id', 'ordered_at']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->uuid('product_variant_id')->nullable();
            $table->uuid('channel_listing_id')->nullable();
            $table->string('external_item_id')->nullable();
            $table->string('external_product_id')->nullable();
            $table->string('external_variant_id')->nullable();
            $table->string('external_sku')->nullable();
            $table->string('external_barcode')->nullable();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_amount');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->string('mapping_status', 20)->default('unmapped');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'])->references(['tenant_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'channel_listing_id'])->references(['tenant_id', 'id'])->on('channel_listings')->restrictOnDelete();
            $table->index(['tenant_id', 'mapping_status']);
            $table->index(['order_id', 'external_item_id']);
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_currency_format CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::table('channel_listings', function (Blueprint $table): void {
            $table->dropUnique('channel_listings_tenant_id_id_unique');
        });
    }
};
