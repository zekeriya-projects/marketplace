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
        Schema::table('order_inventory_allocations', function (Blueprint $table): void {
            $table->unsignedInteger('ordered_quantity')->nullable()->after('product_variant_id');
            $table->unsignedInteger('reserved_quantity')->default(0)->after('ordered_quantity');
            $table->unsignedInteger('released_quantity')->default(0)->after('returned_quantity');
        });

        DB::statement('UPDATE order_inventory_allocations SET ordered_quantity = sold_quantity');
        DB::statement('ALTER TABLE order_inventory_allocations ALTER COLUMN ordered_quantity SET NOT NULL');
        DB::statement('ALTER TABLE order_inventory_allocations DROP CONSTRAINT order_inventory_allocations_sold_positive');
        DB::statement('ALTER TABLE order_inventory_allocations DROP CONSTRAINT order_inventory_allocations_compensation_bounded');
        DB::statement('ALTER TABLE order_inventory_allocations ADD CONSTRAINT order_inventory_allocations_ordered_positive CHECK (ordered_quantity > 0)');
        DB::statement('ALTER TABLE order_inventory_allocations ADD CONSTRAINT order_inventory_allocations_lifecycle_bounded CHECK (reserved_quantity + sold_quantity + released_quantity <= ordered_quantity)');
        DB::statement('ALTER TABLE order_inventory_allocations ADD CONSTRAINT order_inventory_allocations_compensation_bounded CHECK (cancelled_quantity + returned_quantity <= sold_quantity)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_inventory_allocations DROP CONSTRAINT order_inventory_allocations_ordered_positive');
        DB::statement('ALTER TABLE order_inventory_allocations DROP CONSTRAINT order_inventory_allocations_lifecycle_bounded');
        DB::statement('ALTER TABLE order_inventory_allocations DROP CONSTRAINT order_inventory_allocations_compensation_bounded');
        DB::statement('ALTER TABLE order_inventory_allocations ADD CONSTRAINT order_inventory_allocations_sold_positive CHECK (sold_quantity > 0)');
        DB::statement('ALTER TABLE order_inventory_allocations ADD CONSTRAINT order_inventory_allocations_compensation_bounded CHECK (cancelled_quantity + returned_quantity <= sold_quantity)');

        Schema::table('order_inventory_allocations', function (Blueprint $table): void {
            $table->dropColumn(['ordered_quantity', 'reserved_quantity', 'released_quantity']);
        });
    }
};
