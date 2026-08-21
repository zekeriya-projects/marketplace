<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trendyol_listing_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('category_id')->nullable();
            $table->string('name', 150);
            $table->unsignedBigInteger('trendyol_category_id');
            $table->unsignedBigInteger('trendyol_brand_id');
            $table->string('image_url', 2048)->nullable();
            $table->unsignedSmallInteger('vat_rate');
            $table->decimal('dimensional_weight', 8, 2);
            $table->char('origin', 2)->default('TR');
            $table->jsonb('attributes');
            $table->jsonb('required_attribute_ids')->default('[]');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('categories')->nullOnDelete();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trendyol_listing_templates');
    }
};
