<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;

function organizationMember(Tenant $tenant, TenantRole $role): User
{
    $user = User::factory()->create();
    $tenant->users()->attach($user, ['role' => $role->value]);
    $user->update(['active_tenant_id' => $tenant->id]);

    return $user;
}

it('allows an owner to add an existing user with a role', function () {
    $tenant = Tenant::factory()->create();
    $owner = organizationMember($tenant, TenantRole::Owner);
    $newMember = User::factory()->create();

    $this->actingAs($owner)
        ->post("/settings/organizations/{$tenant->id}/members", [
            'email' => $newMember->email,
            'role' => TenantRole::Operator->value,
        ])
        ->assertRedirect();

    expect($newMember->roleFor($tenant))->toBe(TenantRole::Operator);
});

it('prevents operators from managing memberships', function () {
    $tenant = Tenant::factory()->create();
    $operator = organizationMember($tenant, TenantRole::Operator);
    $newMember = User::factory()->create();

    $this->actingAs($operator)
        ->post("/settings/organizations/{$tenant->id}/members", [
            'email' => $newMember->email,
            'role' => TenantRole::Viewer->value,
        ])
        ->assertForbidden();

    expect($newMember->belongsToTenant($tenant))->toBeFalse();
});

it('prevents an admin from assigning privileged roles', function () {
    $tenant = Tenant::factory()->create();
    organizationMember($tenant, TenantRole::Owner);
    $admin = organizationMember($tenant, TenantRole::Admin);
    $newMember = User::factory()->create();

    $this->actingAs($admin)
        ->post("/settings/organizations/{$tenant->id}/members", [
            'email' => $newMember->email,
            'role' => TenantRole::Admin->value,
        ])
        ->assertSessionHasErrors('role');

    expect($newMember->belongsToTenant($tenant))->toBeFalse();
});

it('allows an admin to manage non-privileged roles', function () {
    $tenant = Tenant::factory()->create();
    organizationMember($tenant, TenantRole::Owner);
    $admin = organizationMember($tenant, TenantRole::Admin);
    $operator = organizationMember($tenant, TenantRole::Operator);

    $this->actingAs($admin)
        ->put("/settings/organizations/{$tenant->id}/members/{$operator->id}", [
            'role' => TenantRole::Viewer->value,
        ])
        ->assertRedirect();

    expect($operator->roleFor($tenant))->toBe(TenantRole::Viewer);
});

it('does not allow the last owner to be demoted or removed', function () {
    $tenant = Tenant::factory()->create();
    $owner = organizationMember($tenant, TenantRole::Owner);

    $this->actingAs($owner)
        ->put("/settings/organizations/{$tenant->id}/members/{$owner->id}", [
            'role' => TenantRole::Admin->value,
        ])
        ->assertSessionHasErrors('role');

    $this->actingAs($owner)
        ->delete("/settings/organizations/{$tenant->id}/members/{$owner->id}")
        ->assertSessionHasErrors('role');

    expect($owner->roleFor($tenant))->toBe(TenantRole::Owner);
});

it('cannot mutate a member through a different tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = organizationMember($tenantA, TenantRole::Owner);
    $memberB = organizationMember($tenantB, TenantRole::Viewer);

    $this->actingAs($ownerA)
        ->put("/settings/organizations/{$tenantA->id}/members/{$memberB->id}", [
            'role' => TenantRole::Operator->value,
        ])
        ->assertSessionHasErrors('member');

    expect($memberB->roleFor($tenantB))->toBe(TenantRole::Viewer)
        ->and($memberB->belongsToTenant($tenantA))->toBeFalse();
});
