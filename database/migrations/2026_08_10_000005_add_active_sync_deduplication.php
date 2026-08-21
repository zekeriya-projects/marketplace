<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX sync_operations_one_active_entity_operation ON sync_operations (channel_account_id, operation, entity_type, entity_id) WHERE status IN ('pending', 'running') AND entity_id IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sync_operations_one_active_entity_operation');
    }
};
