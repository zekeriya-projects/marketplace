<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('legal_title')->nullable();
            $table->string('contact_first_name', 100)->nullable();
            $table->string('contact_last_name', 100)->nullable();
            $table->string('mobile_phone', 30)->nullable();
            $table->string('company_email')->nullable();
            $table->string('website')->nullable();
            $table->string('company_phone', 30)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('company_type', 20)->nullable();
            $table->string('tax_office', 150)->nullable();
            $table->text('national_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'legal_title', 'contact_first_name', 'contact_last_name', 'mobile_phone',
                'company_email', 'website', 'company_phone', 'province', 'district',
                'address', 'company_type', 'tax_office', 'national_id',
            ]);
        });
    }
};
