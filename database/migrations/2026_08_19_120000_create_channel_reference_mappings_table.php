<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_reference_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_account_id');
            $table->string('reference_type', 20);
            $table->uuid('reference_id');
            $table->string('external_id');
            $table->string('external_name');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'channel_account_id'])->references(['tenant_id', 'id'])->on('channel_accounts')->cascadeOnDelete();
            $table->unique(['channel_account_id', 'reference_type', 'reference_id'], 'channel_reference_mapping_unique');
            $table->index(['tenant_id', 'reference_type', 'reference_id'], 'channel_reference_mapping_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_reference_mappings');
    }
};
