<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('registers a user and creates their owner organization atomically', function () {
    $response = $this->post('/register', [
        'name' => 'Ada Lovelace',
        'organization_name' => 'Analytical Engines',
        'email' => 'ada@example.com',
        'password' => 'secure-password',
        'password_confirmation' => 'secure-password',
    ]);

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
    $tenant = Tenant::query()->sole();

    expect($tenant->id)->toBeString()->toHaveLength(36)
        ->and($user->active_tenant_id)->toBe($tenant->id)
        ->and($tenant->name)->toBe('Analytical Engines')
        ->and($user->roleFor($tenant))->toBe(TenantRole::Owner);
});

it('authenticates an existing user and regenerates access to their tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);
    $tenant->users()->attach($user, ['role' => TenantRole::Operator->value]);
    $user->update(['active_tenant_id' => $tenant->id]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('logs out and invalidates authentication', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $tenant->users()->attach($user, ['role' => TenantRole::Owner->value]);
    $user->update(['active_tenant_id' => $tenant->id]);

    $this->actingAs($user)->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});
