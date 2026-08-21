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
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'product_variants_tenant_id_id_unique');
        });

        Schema::create('warehouses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        DB::statement('CREATE UNIQUE INDEX warehouses_one_default_per_tenant ON warehouses (tenant_id) WHERE is_default');

        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_variant_id');
            $table->bigInteger('quantity')->default(0);
            $table->unsignedBigInteger('reserved_quantity')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'warehouse_id'])
                ->references(['tenant_id', 'id'])->on('warehouses')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'])
                ->references(['tenant_id', 'id'])->on('product_variants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'warehouse_id', 'product_variant_id']);
            $table->index(['tenant_id', 'product_variant_id']);
        });

        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_quantity_nonnegative CHECK (quantity >= 0)');
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_reserved_nonnegative CHECK (reserved_quantity >= 0)');
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_reserved_not_above_quantity CHECK (reserved_quantity <= quantity)');

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_variant_id');
            $table->string('type', 30);
            $table->bigInteger('quantity_delta');
            $table->unsignedBigInteger('quantity_before');
            $table->unsignedBigInteger('quantity_after');
            $table->nullableUuidMorphs('reference');
            $table->text('note')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'warehouse_id'])
                ->references(['tenant_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'])
                ->references(['tenant_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->index(['tenant_id', 'product_variant_id', 'created_at']);
            $table->index(['tenant_id', 'warehouse_id', 'created_at']);
        });

        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_delta_nonzero CHECK (quantity_delta <> 0)');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_balanced CHECK (quantity_after = quantity_before + quantity_delta)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('warehouses');

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique('product_variants_tenant_id_id_unique');
        });
    }
};
