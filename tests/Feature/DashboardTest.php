<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

it('shows real dashboard metrics scoped to the active tenant', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = tenantMember($tenant);
    $account = wooAccount($tenant, ['status' => ChannelAccountStatus::Active]);
    $otherAccount = wooAccount($other, ['status' => ChannelAccountStatus::Active]);
    Product::factory()->for($tenant)->count(2)->create();
    Product::factory()->for($other)->count(4)->create();
    Order::factory()->for($account, 'account')->create(['tenant_id' => $tenant->id, 'ordered_at' => now(), 'total_amount' => 12500]);
    Order::factory()->for($otherAccount, 'account')->create(['tenant_id' => $other->id, 'ordered_at' => now(), 'total_amount' => 99900]);
    $account->syncOperations()->create(['tenant_id' => $tenant->id, 'operation' => 'connection_test', 'status' => SyncOperationStatus::Failed]);

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')->where('stats.today_orders', 1)->where('stats.products', 2)->where('stats.active_channels', 1)->where('stats.failed_operations', 1)
        ->has('channels', 1)->has('recentOperations', 1)->where('weeklySales.6.amount', 12500));
});
