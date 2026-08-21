<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('monthly_price_amount')->default(0);
            $table->unsignedBigInteger('yearly_price_amount')->default(0);
            $table->char('currency', 3)->default('TRY');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('plan_entitlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature_code', 80);
            $table->string('label');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('limit')->nullable();
            $table->timestamps();
            $table->unique(['subscription_plan_id', 'feature_code']);
        });
        Schema::create('tenant_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->string('billing_cycle', 20)->default('monthly');
            $table->timestampTz('starts_at');
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_ends_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'current_period_ends_at']);
        });

        $now = now();
        $plans = [
            ['code' => 'trial', 'name' => 'Deneme', 'description' => 'Platformu değerlendirmek için 14 günlük başlangıç paketi.', 'monthly' => 0, 'yearly' => 0, 'trial' => 14, 'featured' => false, 'order' => 10, 'features' => [['catalog', 'Ürün entegrasyonu', 100], ['marketplaces', 'Pazaryeri entegrasyonu', 1], ['users', 'Kullanıcı', 2], ['reports', 'Temel raporlama', null]]],
            ['code' => 'starter', 'name' => 'Başlangıç', 'description' => 'Yeni başlayan mağazalar için temel operasyon araçları.', 'monthly' => 99000, 'yearly' => 990000, 'trial' => 0, 'featured' => false, 'order' => 20, 'features' => [['catalog', 'Ürün entegrasyonu', 1000], ['marketplaces', 'Pazaryeri entegrasyonu', 2], ['users', 'Kullanıcı', 3], ['reports', 'Detaylı raporlama', null]]],
            ['code' => 'growth', 'name' => 'Büyüme', 'description' => 'Çok kanallı büyüyen ekipler için kapsamlı yönetim.', 'monthly' => 199000, 'yearly' => 1990000, 'trial' => 0, 'featured' => true, 'order' => 30, 'features' => [['catalog', 'Ürün entegrasyonu', 10000], ['marketplaces', 'Pazaryeri entegrasyonu', 10], ['users', 'Kullanıcı', 10], ['reports', 'Detaylı raporlama', null], ['bulk_operations', 'Toplu işlemler', null]]],
            ['code' => 'enterprise', 'name' => 'Kurumsal', 'description' => 'Yüksek hacimli operasyonlar için özelleştirilebilir çözüm.', 'monthly' => 0, 'yearly' => 0, 'trial' => 0, 'featured' => false, 'order' => 40, 'features' => [['catalog', 'Sınırsız ürün', null], ['marketplaces', 'Sınırsız pazaryeri', null], ['users', 'Sınırsız kullanıcı', null], ['reports', 'Detaylı raporlama', null], ['bulk_operations', 'Toplu işlemler', null], ['priority_support', 'Öncelikli destek', null]]],
        ];
        foreach ($plans as $plan) {
            $planId = (string) Str::uuid7();
            DB::table('subscription_plans')->insert(['id' => $planId, 'code' => $plan['code'], 'name' => $plan['name'], 'description' => $plan['description'], 'monthly_price_amount' => $plan['monthly'], 'yearly_price_amount' => $plan['yearly'], 'currency' => 'TRY', 'trial_days' => $plan['trial'], 'is_featured' => $plan['featured'], 'status' => 'active', 'sort_order' => $plan['order'], 'created_at' => $now, 'updated_at' => $now]);
            foreach ($plan['features'] as [$code, $label, $limit]) {
                DB::table('plan_entitlements')->insert(['id' => (string) Str::uuid7(), 'subscription_plan_id' => $planId, 'feature_code' => $code, 'label' => $label, 'enabled' => true, 'limit' => $limit, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        DB::table('tenants')->orderBy('created_at')->each(function (object $tenant) use ($now): void {
            $planId = DB::table('subscription_plans')->where('code', $tenant->subscription_plan)->value('id') ?? DB::table('subscription_plans')->where('code', 'trial')->value('id');
            DB::table('tenant_subscriptions')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'subscription_plan_id' => $planId, 'status' => $tenant->subscription_status, 'billing_cycle' => 'monthly', 'starts_at' => $tenant->created_at, 'trial_ends_at' => $tenant->subscription_status === 'trialing' ? date('Y-m-d H:i:sP', strtotime($tenant->created_at.' +14 days')) : null, 'created_at' => $now, 'updated_at' => $now]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('subscription_plans');
    }
};
