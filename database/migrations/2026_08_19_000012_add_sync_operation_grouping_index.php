<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_operations', function (Blueprint $table): void {
            $table->index(['tenant_id', 'created_at', 'channel_account_id', 'operation'], 'sync_operations_tenant_grouping_index');
        });
    }

    public function down(): void
    {
        Schema::table('sync_operations', fn (Blueprint $table) => $table->dropIndex('sync_operations_tenant_grouping_index'));
    }
};
