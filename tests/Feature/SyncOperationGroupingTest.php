<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantRole;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\SyncOperation;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function syncLogUser(Tenant $tenant): User
{
    $user = User::factory()->create(['active_tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id, ['role' => TenantRole::Owner->value]);

    return $user;
}

it('groups high volume technical sync records and preserves tenant isolation', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $channel = Channel::query()->where('code', 'woocommerce')->firstOrFail();
    $account = ChannelAccount::factory()->for($tenant)->create(['channel_id' => $channel->id, 'name' => 'Ana mağaza']);
    $foreignAccount = ChannelAccount::factory()->for($other)->create(['channel_id' => $channel->id]);
    foreach (range(1, 12) as $attempt) {
        SyncOperation::query()->create(['tenant_id' => $tenant->id, 'channel_account_id' => $account->id, 'operation' => 'inventory_push', 'status' => $attempt === 12 ? 'failed' : 'succeeded', 'attempt' => 1, 'created_at' => now()->startOfMinute()->addSeconds($attempt)]);
    }
    SyncOperation::query()->create(['tenant_id' => $other->id, 'channel_account_id' => $foreignAccount->id, 'operation' => 'inventory_push', 'status' => 'failed', 'attempt' => 1]);

    $this->actingAs(syncLogUser($tenant))->get('/sync?period=7')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Sync/Index')
        ->has('groups.data', 1)
        ->where('groups.data.0.total_count', 12)
        ->where('groups.data.0.failed_count', 1)
        ->where('summary.events', 12)
        ->where('summary.failed', 1));
});
