<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('channels')->insert([
            ['id' => '019c8ba0-0000-7000-8000-000000000001', 'code' => 'hepsiburada', 'name' => 'Hepsiburada', 'type' => 'marketplace', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => '019c8ba0-0000-7000-8000-000000000002', 'code' => 'amazon', 'name' => 'Amazon', 'type' => 'marketplace', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        DB::table('channels')->whereIn('code', ['hepsiburada', 'amazon'])->delete();
    }
};
