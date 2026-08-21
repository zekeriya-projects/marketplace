<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Integrations\Hepsiburada\DTO\HepsiburadaCredentials;
use App\Integrations\Hepsiburada\HepsiburadaClient;
use App\Jobs\TestChannelConnectionJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\SyncOperation;
use App\Models\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

function hepsiburadaAccount(Tenant $tenant, array $attributes = []): ChannelAccount
{
    return ChannelAccount::factory()->for($tenant)->create([
        'channel_id' => Channel::query()->where('code', 'hepsiburada')->sole()->id,
        ...$attributes,
    ]);
}

function hepsiburadaCredentials(array $overrides = []): array
{
    return ['merchant_id' => 'merchant-123', 'username' => 'hb-user', 'password' => 'hb-secret', 'environment' => 'production', ...$overrides];
}

it('stores Hepsiburada credentials encrypted and exposes only safe hints', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant, TenantRole::Operator);
    $account = hepsiburadaAccount($tenant);
    $payload = hepsiburadaCredentials();

    $this->actingAs($user)->put("/channels/accounts/{$account->id}/hepsiburada/credentials", $payload)->assertRedirect("/channels/accounts/{$account->id}");
    $account->refresh();

    expect($account->getRawOriginal('credentials_encrypted'))->not->toContain('hb-secret')
        ->and($account->credentials_encrypted)->toBe($payload)
        ->and($account->status)->toBe(ChannelAccountStatus::Pending);
    $this->actingAs($user)->get("/channels/accounts/{$account->id}")->assertOk()->assertDontSee('hb-secret')->assertInertia(fn (Assert $page) => $page
        ->component('Channels/Hepsiburada/Show')
        ->where('account.merchant_id', 'merchant-123')
        ->where('account.username_hint', '••••user')
        ->missing('account.credentials_encrypted'));
});

it('validates provider and tenant authorization before saving credentials', function () {
    $tenant = Tenant::factory()->create();
    $account = hepsiburadaAccount($tenant);

    $this->actingAs(tenantMember($tenant))->put("/channels/accounts/{$account->id}/hepsiburada/credentials", hepsiburadaCredentials(['environment' => 'invalid']))->assertSessionHasErrors('environment');
    $this->actingAs(tenantMember($tenant, TenantRole::Viewer))->put("/channels/accounts/{$account->id}/hepsiburada/credentials", hepsiburadaCredentials())->assertForbidden();
    $this->actingAs(tenantMember(Tenant::factory()->create()))->put("/channels/accounts/{$account->id}/hepsiburada/credentials", hepsiburadaCredentials())->assertForbidden();
    expect($account->fresh()->credentials_encrypted)->toBeNull();
});

it('queues a secret-free connection test and activates a valid account', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $account = hepsiburadaAccount($tenant, ['credentials_encrypted' => hepsiburadaCredentials()]);
    $this->actingAs(tenantMember($tenant))->post("/channels/accounts/{$account->id}/test")->assertRedirect();
    Queue::assertPushed(TestChannelConnectionJob::class, fn (TestChannelConnectionJob $job) => ! str_contains(serialize($job), 'hb-secret'));

    Queue::fake([]);
    Http::fake(['https://listing-external.hepsiburada.com/listings/merchantid/merchant-123*' => Http::response(['totalCount' => 0])]);
    $operation = SyncOperation::query()->sole();
    (new TestChannelConnectionJob($operation->id))->handle();

    expect($operation->fresh()->status)->toBe(SyncOperationStatus::Succeeded)
        ->and($account->fresh()->status)->toBe(ChannelAccountStatus::Active);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('hb-user:hb-secret'))
        && $request->hasHeader('User-Agent', 'merchant-123 - MarketplaceSaaS')
        && $request['limit'] === 1 && $request['offset'] === 0);
});

it('uses the SIT endpoint and safely classifies provider failures', function (int $status, SyncErrorCategory $category, bool $retryable) {
    Http::fake(['https://listing-external-sit.hepsiburada.com/*' => Http::response(['message' => 'provider secret payload'], $status)]);
    $result = app(HepsiburadaClient::class)->testConnection(new HepsiburadaCredentials('merchant-1', 'user', 'secret', 'stage'));

    expect($result->errorCategory)->toBe($category)
        ->and($result->retryable)->toBe($retryable)
        ->and($result->safeMessage)->not->toContain('provider secret payload');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://listing-external-sit.hepsiburada.com/'));
})->with([
    [401, SyncErrorCategory::Authentication, false],
    [429, SyncErrorCategory::RateLimited, true],
    [503, SyncErrorCategory::RemoteServer, true],
]);
