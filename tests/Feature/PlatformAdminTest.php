<?php

declare(strict_types=1);

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('allows only platform administrators to list every organization', function (): void {
    Tenant::factory()->count(2)->create();
    $regular = User::factory()->create();
    $admin = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($regular)->get('/platform/organizations')->assertForbidden();

    $this->actingAs($admin)->get('/platform/organizations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Platform/Tenants')
            ->has('tenants.data', 2));
});

it('allows a platform administrator to change organization status', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($admin)
        ->put("/platform/organizations/{$tenant->id}", ['status' => 'disabled', 'subscription_plan' => 'growth', 'subscription_status' => 'active'])
        ->assertRedirect();

    expect($tenant->fresh()->status->value)->toBe('disabled')
        ->and($tenant->fresh()->subscription_plan)->toBe('growth')
        ->and($tenant->fresh()->subscription_status)->toBe('active');
});

it('keeps platform administrators out of tenant operations', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create(['is_platform_admin' => true, 'active_tenant_id' => $tenant->id]);
    $tenant->users()->attach($admin, ['role' => 'owner']);

    $this->actingAs($admin)->get('/dashboard')->assertRedirect('/platform/dashboard');
    $this->actingAs($admin)->get('/products/create')->assertRedirect('/platform/dashboard');
});

it('lets a platform administrator create and update an organization with an owner', function (): void {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $owner = User::factory()->create();

    $this->actingAs($admin)->post('/platform/organizations', [
        'name' => 'Yeni Mağaza', 'slug' => 'yeni-magaza', 'owner_email' => $owner->email,
        'subscription_plan' => 'starter', 'subscription_status' => 'active',
    ])->assertRedirect();

    $tenant = Tenant::query()->where('slug', 'yeni-magaza')->sole();
    expect($owner->fresh()->roleFor($tenant)?->value)->toBe('owner');

    $this->actingAs($admin)->put("/platform/organizations/{$tenant->id}", [
        'name' => 'Güncel Mağaza', 'slug' => 'guncel-magaza', 'status' => 'active',
        'subscription_plan' => 'growth', 'subscription_status' => 'active',
    ])->assertRedirect();
    expect($tenant->fresh()->name)->toBe('Güncel Mağaza');
});

it('manages organization members while preserving the last owner', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $owner = User::factory()->create();
    $operator = User::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);

    $this->actingAs($admin)->post("/platform/organizations/{$tenant->id}/members", ['email' => $operator->email, 'role' => 'operator'])->assertRedirect();
    expect($operator->roleFor($tenant)?->value)->toBe('operator');

    $this->actingAs($admin)->put("/platform/organizations/{$tenant->id}/members/{$operator->id}", ['role' => 'admin'])->assertRedirect();
    expect($operator->roleFor($tenant)?->value)->toBe('admin');

    $this->actingAs($admin)->delete("/platform/organizations/{$tenant->id}/members/{$owner->id}")->assertSessionHasErrors('role');
    expect($owner->belongsToTenant($tenant))->toBeTrue();
});

it('manages subscription packages and their entitlements', function (): void {
    $admin = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($admin)->post('/platform/plans', [
        'code' => 'professional', 'name' => 'Profesyonel', 'description' => 'Gelişmiş paket',
        'monthly_price_amount' => 249000, 'yearly_price_amount' => 2490000, 'currency' => 'TRY',
        'trial_days' => 7, 'is_featured' => true, 'status' => 'active', 'sort_order' => 35,
        'entitlements' => [
            ['feature_code' => 'catalog', 'label' => 'Ürün entegrasyonu', 'enabled' => true, 'limit' => 25000],
            ['feature_code' => 'priority_support', 'label' => 'Öncelikli destek', 'enabled' => true, 'limit' => null],
        ],
    ])->assertRedirect();

    $plan = SubscriptionPlan::query()->where('code', 'professional')->sole();
    expect($plan->entitlements)->toHaveCount(2)->and($plan->monthly_price_amount)->toBe(249000);

    $regular = User::factory()->create();
    $this->actingAs($regular)->post('/platform/plans', [])->assertForbidden();
});

it('assigns and updates one managed subscription per organization', function (): void {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $tenant = Tenant::factory()->create();
    $plan = SubscriptionPlan::query()->where('code', 'growth')->sole();

    $payload = [
        'subscription_plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => 'yearly',
        'starts_at' => now()->toDateString(), 'trial_ends_at' => null,
        'current_period_ends_at' => now()->addYear()->toDateString(), 'notes' => 'Manuel kurumsal satış',
    ];
    $this->actingAs($admin)->put("/platform/organizations/{$tenant->id}/subscription", $payload)->assertRedirect();
    $this->actingAs($admin)->put("/platform/organizations/{$tenant->id}/subscription", [...$payload, 'status' => 'past_due'])->assertRedirect();

    expect($tenant->subscription()->count())->toBe(1)
        ->and($tenant->fresh()->subscription_plan)->toBe('growth')
        ->and($tenant->fresh()->subscription_status)->toBe('past_due')
        ->and($tenant->subscription->billing_cycle)->toBe('yearly');
});

it('enforces disabled package entitlements on tenant routes', function (): void {
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant);
    $plan = SubscriptionPlan::query()->where('code', 'starter')->with('entitlements')->sole();
    $plan->entitlements()->where('feature_code', 'reports')->update(['enabled' => false]);
    $tenant->subscription()->create(['subscription_plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => 'monthly', 'starts_at' => now()]);

    $this->actingAs($user)->get('/reports')->assertForbidden();
    $plan->entitlements()->where('feature_code', 'reports')->update(['enabled' => true]);
    $this->actingAs($user)->get('/reports')->assertOk();
});
