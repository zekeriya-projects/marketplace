<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('options');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
        });
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->uuid('variant_template_id')->nullable();
            $table->jsonb('option_values')->nullable();
            $table->foreign(['tenant_id', 'variant_template_id'])->references(['tenant_id', 'id'])->on('variant_templates')->restrictOnDelete();
            $table->index(['tenant_id', 'variant_template_id']);
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'variant_template_id']);
            $table->dropColumn(['variant_template_id', 'option_values']);
        });
        Schema::dropIfExists('variant_templates');
    }
};
