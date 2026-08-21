<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows profile trial company and member settings for the active tenant', function (): void {
    $tenant = Tenant::factory()->create(['created_at' => now()->subDays(12)]);
    $owner = tenantMember($tenant, TenantRole::Owner);
    $owner->update(['name' => 'Zekeriya Eroglu']);

    $this->actingAs($owner)->get("/settings/organizations/{$tenant->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Organization')
            ->where('profile.name', 'Zekeriya Eroglu')
            ->where('trial.total_days', 14)
            ->where('trial.remaining_days', 2)
            ->where('canUpdate', true));
});

it('updates tenant scoped company details with authorization and validation', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = tenantMember($tenant, TenantRole::Owner);
    $viewer = tenantMember($tenant, TenantRole::Viewer);
    $payload = [
        'name' => $tenant->name,
        'legal_title' => 'Örnek E-Ticaret',
        'contact_first_name' => 'Zekeriya',
        'contact_last_name' => 'Eroglu',
        'mobile_phone' => '(539) 271-5889',
        'company_email' => 'firma@example.com',
        'website' => 'example.com',
        'company_phone' => '(212) 555-1212',
        'province' => 'İstanbul',
        'district' => 'Kadıköy',
        'address' => 'Örnek Mahallesi',
        'company_type' => 'individual',
        'tax_office' => 'Kadıköy',
        'national_id' => '12345678901',
    ];

    $this->actingAs($viewer)->put("/settings/organizations/{$tenant->id}", $payload)->assertForbidden();
    $this->actingAs($owner)->put("/settings/organizations/{$tenant->id}", $payload)->assertRedirect();

    expect($tenant->fresh()->legal_title)->toBe('Örnek E-Ticaret')
        ->and($tenant->fresh()->national_id)->toBe('12345678901');
});

it('lets an authenticated user update only their own profile', function (): void {
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant, TenantRole::Viewer);
    User::factory()->create(['email' => 'used@example.com']);

    $this->actingAs($user)->put('/settings/profile', ['name' => 'Yeni İsim', 'email' => 'used@example.com'])->assertSessionHasErrors('email');
    $this->actingAs($user)->put('/settings/profile', ['name' => 'Yeni İsim', 'email' => 'new@example.com'])->assertRedirect();

    expect($user->fresh()->name)->toBe('Yeni İsim')->and($user->fresh()->email)->toBe('new@example.com');
});
