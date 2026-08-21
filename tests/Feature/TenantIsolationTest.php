<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantRole;
use App\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

it('prevents a user from reading another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = tenantMember($tenantA);

    $this->actingAs($userA)
        ->get("/settings/organizations/{$tenantB->id}")
        ->assertForbidden();
});

it('prevents a user from mutating another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
    $userA = tenantMember($tenantA);

    $this->actingAs($userA)
        ->put("/settings/organizations/{$tenantB->id}", ['name' => 'Compromised'])
        ->assertForbidden();

    expect($tenantB->fresh()->name)->toBe('Tenant B');
});

it('prevents switching to a tenant without membership', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = tenantMember($tenantA);

    $this->actingAs($userA)
        ->post("/settings/organizations/{$tenantB->id}/switch")
        ->assertForbidden();

    expect($userA->fresh()->active_tenant_id)->toBe($tenantA->id);
});

it('allows a member to switch between their organizations', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = tenantMember($tenantA);
    $tenantB->users()->attach($user, ['role' => TenantRole::Viewer->value]);

    $this->actingAs($user)
        ->post("/settings/organizations/{$tenantB->id}/switch")
        ->assertRedirect('/dashboard');

    expect($user->fresh()->active_tenant_id)->toBe($tenantB->id);
});

it('repairs an active tenant that is not backed by membership', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = tenantMember($tenantA);
    $user->forceFill(['active_tenant_id' => $tenantB->id])->save();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('tenant.id', $tenantA->id));

    expect($user->fresh()->active_tenant_id)->toBe($tenantA->id);
});

it('allows owners and admins to update their tenant but rejects lower roles', function (TenantRole $role, bool $allowed) {
    $tenant = Tenant::factory()->create(['name' => 'Original']);
    $user = tenantMember($tenant, $role);

    $response = $this->actingAs($user)
        ->put("/settings/organizations/{$tenant->id}", ['name' => 'Updated']);

    if ($allowed) {
        $response->assertRedirect();
        expect($tenant->fresh()->name)->toBe('Updated');
    } else {
        $response->assertForbidden();
        expect($tenant->fresh()->name)->toBe('Original');
    }
})->with([
    'owner' => [TenantRole::Owner, true],
    'admin' => [TenantRole::Admin, true],
    'operator' => [TenantRole::Operator, false],
    'viewer' => [TenantRole::Viewer, false],
]);
