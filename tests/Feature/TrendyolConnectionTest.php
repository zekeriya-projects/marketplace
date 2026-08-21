<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Integrations\Trendyol\DTO\TrendyolCredentials;
use App\Integrations\Trendyol\TrendyolClient;
use App\Jobs\TestChannelConnectionJob;
use App\Models\SyncOperation;
use App\Models\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('stores Trendyol credentials encrypted and returns only safe hints', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant, TenantRole::Operator);
    $account = trendyolAccount($tenant);
    $payload = trendyolCredentials();

    $this->actingAs($user)->put("/channels/accounts/{$account->id}/trendyol/credentials", $payload)->assertRedirect("/channels/accounts/{$account->id}");
    $account->refresh();
    expect($account->getRawOriginal('credentials_encrypted'))->not->toContain($payload['api_secret'])->and($account->credentials_encrypted)->toBe($payload)->and($account->status)->toBe(ChannelAccountStatus::Pending);
    $this->actingAs($user)->get("/channels/accounts/{$account->id}")->assertOk()->assertDontSee($payload['api_secret'])->assertInertia(fn (Assert $page) => $page
        ->component('Channels/Trendyol/Show')->where('account.seller_id', '123456')->where('account.api_key_hint', '••••-key')->missing('account.credentials_encrypted'));
});

it('validates Trendyol credentials and prevents unauthorized tenant writes', function () {
    $tenant = Tenant::factory()->create();
    $account = trendyolAccount($tenant);
    $this->actingAs(tenantMember($tenant))->put("/channels/accounts/{$account->id}/trendyol/credentials", trendyolCredentials(['seller_id' => 'abc', 'environment' => 'invalid']))->assertSessionHasErrors(['seller_id', 'environment']);
    $this->actingAs(tenantMember($tenant, TenantRole::Viewer))->put("/channels/accounts/{$account->id}/trendyol/credentials", trendyolCredentials())->assertForbidden();
    $this->actingAs(tenantMember(Tenant::factory()->create()))->put("/channels/accounts/{$account->id}/trendyol/credentials", trendyolCredentials())->assertForbidden();
    expect($account->fresh()->credentials_encrypted)->toBeNull();
});

it('queues a secret-free tenant scoped connection test', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $account = trendyolAccount($tenant, ['credentials_encrypted' => trendyolCredentials()]);
    $this->actingAs(tenantMember($tenant))->post("/channels/accounts/{$account->id}/test")->assertRedirect();
    $operation = SyncOperation::query()->sole();
    expect($operation->tenant_id)->toBe($tenant->id)->and($operation->operation)->toBe('connection_test');
    Queue::assertPushed(TestChannelConnectionJob::class, fn (TestChannelConnectionJob $job) => ! str_contains(serialize($job), 'trend-api-secret'));
});

it('uses Trendyol basic auth and required headers then activates the account', function () {
    Http::fake(['https://apigw.trendyol.com/integration/sellers/123456/addresses' => Http::response(['supplierAddresses' => []])]);
    $account = trendyolAccount(Tenant::factory()->create(), ['credentials_encrypted' => trendyolCredentials()]);
    $operation = $account->syncOperations()->create(['tenant_id' => $account->tenant_id, 'operation' => 'connection_test']);
    (new TestChannelConnectionJob($operation->id))->handle();

    expect($operation->fresh()->status)->toBe(SyncOperationStatus::Succeeded)->and($account->fresh()->status)->toBe(ChannelAccountStatus::Active);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('trend-api-key:trend-api-secret'))
        && $request->hasHeader('User-Agent', '123456 - MarketplaceSaaS') && $request->hasHeader('storeFrontCode', 'TR'));
});

it('uses the stage endpoint and safely translates provider failures', function () {
    Http::fake(['https://stageapigw.trendyol.com/*' => Http::response(['exception' => 'secret provider payload'], 401)]);
    $result = app(TrendyolClient::class)->testConnection(new TrendyolCredentials('77', 'key', 'secret', 'stage'));
    expect($result->errorCategory)->toBe(SyncErrorCategory::Authentication)->and($result->safeMessage)->not->toContain('secret provider payload');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://stageapigw.trendyol.com/'));
});

it('marks rate limits and remote failures retryable without leaking responses', function (int $status, SyncErrorCategory $category) {
    Http::fake(['*' => Http::response(['message' => 'remote database password'], $status)]);
    $result = app(TrendyolClient::class)->testConnection(new TrendyolCredentials('1', 'key', 'secret', 'production'));
    expect($result->successful)->toBeFalse()->and($result->retryable)->toBeTrue()->and($result->errorCategory)->toBe($category)->and($result->safeMessage)->not->toContain('database password');
})->with([[429, SyncErrorCategory::RateLimited], [503, SyncErrorCategory::RemoteServer]]);
