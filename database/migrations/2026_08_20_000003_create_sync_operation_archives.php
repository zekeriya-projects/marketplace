<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_operation_archives', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');
            $table->uuid('channel_account_id');
            $table->string('operation', 50);
            $table->timestamp('bucket_at');
            $table->string('status', 20);
            $table->unsignedBigInteger('operation_count');
            $table->timestamp('latest_at');
            $table->text('latest_error')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'channel_account_id'])->references(['tenant_id', 'id'])->on('channel_accounts')->cascadeOnDelete();
            $table->unique(['tenant_id', 'channel_account_id', 'operation', 'bucket_at', 'status'], 'sync_operation_archives_bucket_unique');
            $table->index(['tenant_id', 'bucket_at', 'channel_account_id', 'operation'], 'sync_operation_archives_grouping_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_operation_archives');
    }
};
