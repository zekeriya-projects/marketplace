<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Sync\Actions\CreateSyncOperation;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Integrations\ConnectorManager;
use App\Integrations\Contracts\ChannelConnector;
use App\Integrations\Contracts\DTO\ChannelRequest;
use App\Integrations\Contracts\Results\SyncResult;
use App\Jobs\ChannelSyncJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncOperation;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

final class SuccessfulFoundationSyncJob extends ChannelSyncJob
{
    protected function execute(SyncOperation $operation): SyncResult
    {
        return SyncResult::success(['remote_id' => 'safe-reference']);
    }
}

it('provides the global reference channels with integration categories', function () {
    expect(Channel::query()->orderBy('code')->pluck('code')->all())->toBe(['amazon', 'hepsiburada', 'trendyol', 'woocommerce'])
        ->and(Channel::query()->whereIn('code', ['amazon', 'hepsiburada', 'trendyol'])->pluck('type')->unique()->all())->toBe(['marketplace']);
});

it('creates channel accounts only for the active tenant and keeps viewers read only', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $operator = tenantMember($tenant, TenantRole::Operator);
    $viewer = tenantMember($tenant, TenantRole::Viewer);
    $channel = Channel::query()->where('code', 'woocommerce')->sole();
    ChannelAccount::factory()->for($otherTenant)->create(['name' => 'Hidden account']);

    $this->actingAs($operator)->post('/channels/accounts', ['channel_id' => $channel->id, 'name' => 'Main store'])->assertRedirect('/channels?category=ecommerce');
    $this->actingAs($viewer)->post('/channels/accounts', ['channel_id' => $channel->id, 'name' => 'Blocked'])->assertForbidden();

    $account = ChannelAccount::query()->where('name', 'Main store')->sole();
    expect($account->tenant_id)->toBe($tenant->id)->and($account->status)->toBe(ChannelAccountStatus::Pending);
    $this->actingAs($operator)->get('/channels?category=ecommerce')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Channels/Index')
        ->where('category', 'ecommerce')
        ->has('channels', 1)
        ->where('channels.0.code', 'woocommerce')
        ->has('accounts', 1)
        ->where('accounts.0.name', 'Main store'));
});

it('separates integrations by category and keeps shipping ready for future providers', function () {
    $tenant = Tenant::factory()->create();
    $operator = tenantMember($tenant, TenantRole::Operator);

    $this->actingAs($operator)->get('/channels')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('category', 'marketplace')
        ->has('channels', 3)
        ->where('channels.0.code', 'amazon')
        ->where('channels.0.connection_available', false)
        ->where('channels.1.code', 'hepsiburada')
        ->where('channels.1.connection_available', true)
        ->where('channels.2.code', 'trendyol')
        ->where('channels.2.connection_available', true));

    $this->actingAs($operator)->get('/channels?category=shipping')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('category', 'shipping')
        ->has('channels', 0)
        ->has('accounts', 0));

    $this->actingAs($operator)->get('/channels?category=unsupported')->assertSessionHasErrors('category');
});

it('encrypts credentials at rest and never serializes them', function () {
    $account = ChannelAccount::factory()->create(['credentials_encrypted' => ['client_secret' => 'do-not-expose']]);
    $raw = $account->getRawOriginal('credentials_encrypted');

    expect($raw)->not->toContain('do-not-expose')
        ->and($account->fresh()->credentials_encrypted)->toBe(['client_secret' => 'do-not-expose'])
        ->and($account->toArray())->not->toHaveKey('credentials_encrypted');
});

it('enforces tenant agreement for channel listing mappings in the database', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $account = ChannelAccount::factory()->for($tenant)->create();
    $product = Product::factory()->for($otherTenant)->create();
    $variant = ProductVariant::factory()->for($product)->create(['tenant_id' => $otherTenant->id]);

    expect(fn () => ChannelListing::query()->create([
        'tenant_id' => $tenant->id,
        'channel_account_id' => $account->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
    ]))->toThrow(QueryException::class);
});

it('creates tenant safe sync operations and tracks a queued job lifecycle', function () {
    $account = ChannelAccount::factory()->create();
    $operation = app(CreateSyncOperation::class)->execute($account, 'inventory_push', context: ['warehouse_id' => 'safe-id']);

    (new SuccessfulFoundationSyncJob($operation->id))->handle();

    $operation->refresh();
    expect($operation->tenant_id)->toBe($account->tenant_id)
        ->and($operation->status)->toBe(SyncOperationStatus::Succeeded)
        ->and($operation->attempt)->toBe(1)
        ->and($operation->started_at)->not->toBeNull()
        ->and($operation->finished_at)->not->toBeNull()
        ->and($operation->context)->toBe(['remote_id' => 'safe-reference']);
});

it('resolves connectors by channel without coupling the core to adapters', function () {
    $connector = new class implements ChannelConnector
    {
        public function testConnection(ChannelRequest $request): SyncResult
        {
            return SyncResult::success();
        }

        public function pullProducts(ChannelRequest $request): SyncResult
        {
            return SyncResult::success();
        }

        public function pushProduct(ChannelRequest $request): SyncResult
        {
            return SyncResult::success();
        }

        public function updateInventory(ChannelRequest $request): SyncResult
        {
            return SyncResult::success();
        }

        public function updatePrice(ChannelRequest $request): SyncResult
        {
            return SyncResult::success();
        }

        public function pullOrders(ChannelRequest $request): SyncResult
        {
            return SyncResult::success();
        }

        public function updateOrderStatus(ChannelRequest $request): SyncResult
        {
            return SyncResult::success();
        }

        public function pullOrder(ChannelRequest $request): SyncResult
        {
            return SyncResult::success();
        }
    };
    $manager = app(ConnectorManager::class);
    $manager->register('fake', $connector);

    expect($manager->for('fake'))->toBe($connector);
});
