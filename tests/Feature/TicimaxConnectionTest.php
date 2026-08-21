<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Integrations\Ticimax\Contracts\TicimaxSoapTransport;
use App\Integrations\Ticimax\DTO\TicimaxCredentials;
use App\Integrations\Ticimax\TicimaxClient;
use App\Integrations\Ticimax\TicimaxUrlGuard;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

function ticimaxAccount(Tenant $tenant, array $attributes = []): ChannelAccount
{
    $channel = Channel::query()->firstOrCreate(['code' => 'ticimax'], ['name' => 'Ticimax', 'type' => 'storefront', 'is_active' => true]);

    return ChannelAccount::factory()->for($tenant)->for($channel)->create($attributes);
}

it('stores Ticimax credentials encrypted and never returns the member code', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant, TenantRole::Operator);
    $account = ticimaxAccount($tenant);
    $guard = Mockery::mock(TicimaxUrlGuard::class);
    $guard->shouldReceive('assertSafe')->once()->with('https://shop.example.com/');
    app()->instance(TicimaxUrlGuard::class, $guard);

    $this->actingAs($user)->put("/channels/accounts/{$account->id}/ticimax/credentials", [
        'store_url' => 'https://shop.example.com/',
        'member_code' => 'very-secret-member-code',
    ])->assertRedirect("/channels/accounts/{$account->id}");

    $account->refresh();
    expect($account->getRawOriginal('credentials_encrypted'))->not->toContain('very-secret-member-code')
        ->and($account->credentials_encrypted)->toBe(['store_url' => 'https://shop.example.com', 'member_code' => 'very-secret-member-code'])
        ->and($account->status)->toBe(ChannelAccountStatus::Pending);

    $this->actingAs($user)->get("/channels/accounts/{$account->id}")->assertInertia(fn (Assert $page) => $page
        ->component('Channels/Ticimax/Show')
        ->where('account.store_url', 'https://shop.example.com')
        ->where('account.member_code_hint', '••••code')
        ->missing('account.credentials_encrypted'));
});

it('keeps Ticimax credentials tenant scoped and authorized', function () {
    $tenant = Tenant::factory()->create();
    $account = ticimaxAccount($tenant);
    $payload = ['store_url' => 'https://shop.example.com', 'member_code' => 'secret'];

    $this->actingAs(tenantMember($tenant, TenantRole::Viewer))->put("/channels/accounts/{$account->id}/ticimax/credentials", $payload)->assertForbidden();
    $this->actingAs(tenantMember(Tenant::factory()->create()))->put("/channels/accounts/{$account->id}/ticimax/credentials", $payload)->assertForbidden();
    expect($account->fresh()->credentials_encrypted)->toBeNull();
});

it('uses the documented product count operation for connection tests', function () {
    $transport = Mockery::mock(TicimaxSoapTransport::class);
    $transport->shouldReceive('call')->once()->with(
        'https://shop.example.com/Servis/UrunServis.svc?wsdl',
        'SelectUrunCount',
        Mockery::on(fn (array $arguments): bool => $arguments['UyeKodu'] === 'member-code' && $arguments['f']['Aktif'] === -1),
    )->andReturn((object) ['SelectUrunCountResult' => 12]);
    $guard = Mockery::mock(TicimaxUrlGuard::class);
    $guard->shouldReceive('assertSafe')->once()->with('https://shop.example.com');

    $result = (new TicimaxClient($transport, $guard))->testConnection(new TicimaxCredentials('https://shop.example.com', 'member-code'));
    expect($result->successful)->toBeTrue();
});

it('rejects unsafe Ticimax targets without contacting SOAP', function () {
    $transport = Mockery::mock(TicimaxSoapTransport::class);
    $transport->shouldNotReceive('call');
    $result = (new TicimaxClient($transport, new TicimaxUrlGuard))->testConnection(new TicimaxCredentials('https://127.0.0.1', 'member-code'));

    expect($result->successful)->toBeFalse()->and($result->errorCategory)->toBe(SyncErrorCategory::Validation);
});
