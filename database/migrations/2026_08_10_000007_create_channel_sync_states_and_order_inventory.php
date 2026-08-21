<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_sync_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_account_id');
            $table->string('resource_type', 50);
            $table->string('cursor')->nullable();
            $table->timestampTz('last_synced_from')->nullable();
            $table->timestampTz('last_synced_to')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'channel_account_id'])->references(['tenant_id', 'id'])->on('channel_accounts')->cascadeOnDelete();
            $table->unique(['channel_account_id', 'resource_type']);
            $table->index(['tenant_id', 'resource_type']);
        });
        Schema::table('orders', fn (Blueprint $table) => $table->timestampTz('inventory_applied_at')->nullable()->after('imported_at'));
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('inventory_applied_at'));
        Schema::dropIfExists('channel_sync_states');
    }
};
