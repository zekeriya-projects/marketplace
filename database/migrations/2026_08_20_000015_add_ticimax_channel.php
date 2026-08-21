<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('channels')->insertOrIgnore([
            'id' => '019c8ba0-0000-7000-8000-000000000003',
            'code' => 'ticimax',
            'name' => 'Ticimax',
            'type' => 'storefront',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('channels')->where('code', 'ticimax')->delete();
    }
};
