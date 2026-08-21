<?php

declare(strict_types=1);

use App\Domain\Channels\Actions\ImportWooCommerceProduct;
use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Sync\Enums\SyncErrorCategory;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Exceptions\RetryableSyncException;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Integrations\ConnectorManager;
use App\Integrations\WooCommerce\DTO\WooCommerceCredentials;
use App\Integrations\WooCommerce\WooCommerceCatalogImporter;
use App\Integrations\WooCommerce\WooCommerceClient;
use App\Integrations\WooCommerce\WooCommerceConnector;
use App\Integrations\WooCommerce\WooCommerceUrlGuard;
use App\Jobs\TestChannelConnectionJob;
use App\Models\SyncOperation;
use App\Models\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array<string, string> */
function wooRequestQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

it('stores WooCommerce credentials encrypted and returns only a masked key hint', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant, TenantRole::Operator);
    $account = wooAccount($tenant);
    $payload = wooCredentialsPayload();

    $this->actingAs($user)->put("/channels/accounts/{$account->id}/woocommerce/credentials", $payload)->assertRedirect("/channels/accounts/{$account->id}");
    $account->refresh();

    expect($account->getRawOriginal('credentials_encrypted'))->not->toContain($payload['consumer_secret'])
        ->and($account->credentials_encrypted)->toBe([...$payload, 'store_url' => 'https://shop.example.com'])
        ->and($account->status)->toBe(ChannelAccountStatus::Pending);

    $this->actingAs($user)->get("/channels/accounts/{$account->id}")->assertOk()->assertDontSee($payload['consumer_secret'])->assertInertia(fn (Assert $page) => $page
        ->component('Channels/WooCommerce/Show')
        ->where('account.store_url', 'https://shop.example.com')
        ->where('account.consumer_key_hint', '••••aaaa')
        ->missing('account.credentials_encrypted'));
});

it('rejects insecure URLs and malformed API keys', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant);
    $account = wooAccount($tenant);

    $this->actingAs($user)->put("/channels/accounts/{$account->id}/woocommerce/credentials", wooCredentialsPayload([
        'store_url' => 'http://127.0.0.1/admin?token=secret',
        'consumer_key' => 'invalid',
    ]))->assertSessionHasErrors(['store_url', 'consumer_key']);
    expect($account->fresh()->credentials_encrypted)->toBeNull();
});

it('prevents viewers and other tenants from changing WooCommerce credentials', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $account = wooAccount($tenant);

    $this->actingAs(tenantMember($tenant, TenantRole::Viewer))->put("/channels/accounts/{$account->id}/woocommerce/credentials", wooCredentialsPayload())->assertForbidden();
    $this->actingAs(tenantMember($otherTenant))->put("/channels/accounts/{$account->id}/woocommerce/credentials", wooCredentialsPayload())->assertForbidden();
    expect($account->fresh()->credentials_encrypted)->toBeNull();
});

it('queues a tenant scoped connection test without exposing credentials in the job', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $user = tenantMember($tenant);
    $account = wooAccount($tenant, ['credentials_encrypted' => wooCredentialsPayload()]);

    $this->actingAs($user)->post("/channels/accounts/{$account->id}/test")->assertRedirect("/channels/accounts/{$account->id}");
    $operation = SyncOperation::query()->sole();

    expect($operation->tenant_id)->toBe($tenant->id)->and($operation->operation)->toBe('connection_test');
    Queue::assertPushed(TestChannelConnectionJob::class, fn (TestChannelConnectionJob $job) => $job->syncOperationId === $operation->id && ! str_contains(serialize($job), 'cs_'));
});

it('authenticates with basic auth and activates the account after a successful fake response', function () {
    Http::fake([
        'https://shop.example.com/wp-json/wc/v3/data' => Http::response(['data' => []]),
        'https://shop.example.com/wp-json/wc/v3/data/currencies/current' => Http::response(['code' => 'TRY']),
    ]);
    $guard = Mockery::mock(WooCommerceUrlGuard::class);
    $guard->shouldReceive('assertSafe')->twice()->with('https://shop.example.com');
    $client = new WooCommerceClient($guard);
    app(ConnectorManager::class)->register('woocommerce', new WooCommerceConnector($client, new WooCommerceCatalogImporter($client, app(ImportWooCommerceProduct::class))));
    $account = wooAccount(Tenant::factory()->create(), ['credentials_encrypted' => wooCredentialsPayload(['store_url' => 'https://shop.example.com'])]);
    $operation = $account->syncOperations()->create(['tenant_id' => $account->tenant_id, 'operation' => 'connection_test']);

    (new TestChannelConnectionJob($operation->id))->handle();

    $account->refresh();
    expect($operation->fresh()->status)->toBe(SyncOperationStatus::Succeeded)
        ->and($account->status)->toBe(ChannelAccountStatus::Active)
        ->and($account->last_connected_at)->not->toBeNull();
    Http::assertSent(fn (Request $request) => $request->url() === 'https://shop.example.com/wp-json/wc/v3/data'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('ck_'.str_repeat('a', 40).':'.'cs_'.str_repeat('b', 40))));
});

it('translates authentication failures without leaking remote payloads', function () {
    Http::fake(['*' => Http::response(['message' => 'secret provider detail'], 401)]);
    $guard = Mockery::mock(WooCommerceUrlGuard::class);
    $guard->shouldReceive('assertSafe')->once();
    $client = new WooCommerceClient($guard);

    $result = $client->testConnection(new WooCommerceCredentials('https://shop.example.com', 'ck_key', 'cs_secret'));

    expect($result->successful)->toBeFalse()
        ->and($result->errorCategory)->toBe(SyncErrorCategory::Authentication)
        ->and($result->safeMessage)->not->toContain('secret provider detail');
});

it('marks retryable remote failures safely when queue retries are exhausted', function () {
    Http::fake(['*' => Http::response(['message' => 'database password from remote'], 503)]);
    $guard = Mockery::mock(WooCommerceUrlGuard::class);
    $guard->shouldReceive('assertSafe')->once();
    $client = new WooCommerceClient($guard);
    app(ConnectorManager::class)->register('woocommerce', new WooCommerceConnector($client, new WooCommerceCatalogImporter($client, app(ImportWooCommerceProduct::class))));
    $account = wooAccount(Tenant::factory()->create(), ['credentials_encrypted' => wooCredentialsPayload(['store_url' => 'https://shop.example.com'])]);
    $operation = $account->syncOperations()->create(['tenant_id' => $account->tenant_id, 'operation' => 'connection_test']);
    $job = new TestChannelConnectionJob($operation->id);

    try {
        $job->handle();
        $this->fail('A retryable exception should be raised.');
    } catch (RetryableSyncException $exception) {
        $job->failed($exception);
    }

    $operation->refresh();
    expect($operation->status)->toBe(SyncOperationStatus::Failed)
        ->and($operation->error_category)->toBe(SyncErrorCategory::RemoteServer)
        ->and($operation->safe_error_message)->not->toContain('database password')
        ->and($account->fresh()->status)->toBe(ChannelAccountStatus::Error);
});

it('blocks local and private network targets before making an HTTP request', function () {
    Http::fake();
    $result = (new WooCommerceClient(new WooCommerceUrlGuard))->testConnection(new WooCommerceCredentials('https://127.0.0.1', 'ck_key', 'cs_secret'));

    expect($result->errorCategory)->toBe(SyncErrorCategory::Validation);
    Http::assertNothingSent();
});

it('allows an explicitly configured local WooCommerce host only in local development', function () {
    app()->detectEnvironment(fn (): string => 'local');
    config()->set('integrations.woocommerce.allow_local_urls', true);
    config()->set('integrations.woocommerce.local_hosts', ['host.docker.internal']);
    Http::fake(['http://host.docker.internal:8090/wp-json/wc/v3/data*' => Http::response(['data' => []])]);

    $result = (new WooCommerceClient(new WooCommerceUrlGuard))->testConnection(
        new WooCommerceCredentials('http://host.docker.internal:8090', 'ck_key', 'cs_secret'),
    );

    expect($result->successful)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/wp-json/wc/v3/data'
        && ! $request->hasHeader('Authorization')
        && wooRequestQuery($request)['oauth_consumer_key'] === 'ck_key'
        && wooRequestQuery($request)['oauth_signature_method'] === 'HMAC-SHA256'
        && is_string(wooRequestQuery($request)['oauth_signature']));
});

it('bridges localhost WooCommerce URLs to the Docker host in local development', function () {
    app()->detectEnvironment(fn (): string => 'local');
    config()->set('integrations.woocommerce.allow_local_urls', true);
    config()->set('integrations.woocommerce.local_hosts', ['localhost']);
    config()->set('integrations.woocommerce.local_host_bridge', 'host.docker.internal');
    Http::fake(['http://host.docker.internal/wordpress/wp-json/wc/v3/data*' => Http::response(['data' => []])]);

    $result = (new WooCommerceClient(new WooCommerceUrlGuard))->testConnection(
        new WooCommerceCredentials('http://localhost/wordpress', 'ck_key', 'cs_secret'),
    );

    expect($result->successful)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://host.docker.internal/wordpress/wp-json/wc/v3/data?')
        && wooRequestQuery($request)['oauth_signature_method'] === 'HMAC-SHA256');
});

it('bridges an explicitly allowed local network store URL in local development', function () {
    app()->detectEnvironment(fn (): string => 'local');
    config()->set('integrations.woocommerce.allow_local_urls', true);
    config()->set('integrations.woocommerce.local_hosts', ['172.30.224.1']);
    config()->set('integrations.woocommerce.local_host_bridge', '172.30.224.1:8081');

    Http::fake(['http://172.30.224.1:8081/wordpress/wp-json/wc/v3/data*' => Http::response([])]);

    $result = app(WooCommerceClient::class)->testConnection(new WooCommerceCredentials(
        'http://172.30.224.1/wordpress/',
        'ck_'.str_repeat('a', 40),
        'cs_'.str_repeat('b', 40),
    ));

    expect($result->successful)->toBeTrue();
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'http://172.30.224.1:8081/wordpress/wp-json/wc/v3/data?')
        && wooRequestQuery($request)['oauth_signature_method'] === 'HMAC-SHA256');
});

it('still rejects local WooCommerce hosts when the development opt in is disabled', function () {
    config()->set('integrations.woocommerce.allow_local_urls', false);
    config()->set('integrations.woocommerce.local_hosts', ['host.docker.internal']);
    Http::fake();

    $result = (new WooCommerceClient(new WooCommerceUrlGuard))->testConnection(
        new WooCommerceCredentials('http://host.docker.internal:8090', 'ck_key', 'cs_secret'),
    );

    expect($result->errorCategory)->toBe(SyncErrorCategory::Validation);
    Http::assertNothingSent();
});
