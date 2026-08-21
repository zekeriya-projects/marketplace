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
        Schema::create('channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('type', 30)->default('marketplace');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('channels')->insert([
            ['id' => '019c4a00-0000-7000-8000-000000000001', 'code' => 'woocommerce', 'name' => 'WooCommerce', 'type' => 'storefront', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => '019c4a00-0000-7000-8000-000000000002', 'code' => 'trendyol', 'name' => 'Trendyol', 'type' => 'marketplace', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('channel_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_id');
            $table->string('name');
            $table->text('credentials_encrypted')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->string('status', 20)->default('pending');
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_id')->references('id')->on('channels')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'channel_id']);
            $table->unique(['tenant_id', 'channel_id', 'name']);
        });

        Schema::create('channel_listings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_account_id');
            $table->uuid('product_id');
            $table->uuid('product_variant_id')->nullable();
            $table->string('external_product_id')->nullable();
            $table->string('external_variant_id')->nullable();
            $table->string('external_sku')->nullable();
            $table->string('external_barcode')->nullable();
            $table->string('status', 20)->default('unmapped');
            $table->unsignedBigInteger('channel_price_amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'channel_account_id'])->references(['tenant_id', 'id'])->on('channel_accounts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'])->references(['tenant_id', 'id'])->on('product_variants')->cascadeOnDelete();
            $table->index(['tenant_id', 'product_variant_id']);
            $table->index(['channel_account_id', 'external_product_id']);
            $table->index(['channel_account_id', 'external_sku']);
        });

        DB::statement("CREATE UNIQUE INDEX channel_listings_external_entity_unique ON channel_listings (channel_account_id, external_product_id, COALESCE(external_variant_id, '')) WHERE external_product_id IS NOT NULL");

        Schema::create('sync_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_account_id');
            $table->string('operation', 50);
            $table->nullableUuidMorphs('entity');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempt')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_category', 30)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('safe_error_message')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'channel_account_id'])->references(['tenant_id', 'id'])->on('channel_accounts')->cascadeOnDelete();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['channel_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_operations');
        Schema::dropIfExists('channel_listings');
        Schema::dropIfExists('channel_accounts');
        Schema::dropIfExists('channels');
    }
};
