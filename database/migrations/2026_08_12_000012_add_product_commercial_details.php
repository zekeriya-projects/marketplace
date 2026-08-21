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
        Schema::table('products', function (Blueprint $table): void {
            $table->string('short_name')->nullable();
            $table->string('invoice_name')->nullable();
            $table->string('custom_code_1', 100)->nullable();
            $table->string('custom_code_2', 100)->nullable();
            $table->unsignedBigInteger('compare_at_price_amount')->nullable();
            $table->unsignedBigInteger('purchase_price_amount')->nullable();
            $table->decimal('desi', 8, 2)->nullable();
            $table->decimal('desi_2', 8, 2)->nullable();
            $table->unsignedSmallInteger('vat_rate')->default(20);
            $table->decimal('excise_tax_rate', 5, 2)->default(0);
            $table->decimal('communication_tax_rate', 5, 2)->default(0);
            $table->boolean('disable_external_sync')->default(false);
            $table->string('vat_exemption_code', 50)->nullable();
            $table->date('expiration_date')->nullable();
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_tax_rates_valid CHECK (vat_rate <= 100 AND excise_tax_rate BETWEEN 0 AND 100 AND communication_tax_rate BETWEEN 0 AND 100)');

        Schema::create('product_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->string('disk', 30)->default('public');
            $table->string('path');
            $table->string('alt_text')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->index(['tenant_id', 'product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_tax_rates_valid');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['short_name', 'invoice_name', 'custom_code_1', 'custom_code_2', 'compare_at_price_amount', 'purchase_price_amount', 'desi', 'desi_2', 'vat_rate', 'excise_tax_rate', 'communication_tax_rate', 'disable_external_sync', 'vat_exemption_code', 'expiration_date']);
        });
    }
};
