<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_listings', function (Blueprint $table): void {
            $table->index(['channel_account_id', 'external_barcode'], 'channel_listings_account_barcode_index');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['tenant_id', 'channel_account_id', 'ordered_at'], 'orders_tenant_account_ordered_index');
            $table->index(['tenant_id', 'status', 'ordered_at'], 'orders_tenant_status_ordered_index');
        });
        Schema::table('sync_operations', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status', 'created_at'], 'sync_operations_tenant_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('sync_operations', fn (Blueprint $table) => $table->dropIndex('sync_operations_tenant_status_created_index'));
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_tenant_account_ordered_index');
            $table->dropIndex('orders_tenant_status_ordered_index');
        });
        Schema::table('channel_listings', fn (Blueprint $table) => $table->dropIndex('channel_listings_account_barcode_index'));
    }
};
