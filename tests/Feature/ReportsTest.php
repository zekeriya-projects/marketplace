<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantRole;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function reportUser(Tenant $tenant): User
{
    $user = User::factory()->create(['active_tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id, ['role' => TenantRole::Owner->value]);

    return $user;
}

it('reports tenant sales and excludes other tenants and dates', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $channel = Channel::query()->where('code', 'woocommerce')->firstOrFail();
    $account = ChannelAccount::factory()->for($tenant)->create(['channel_id' => $channel->id, 'name' => 'Ana mağaza']);
    $otherAccount = ChannelAccount::factory()->for($other)->create(['channel_id' => $channel->id]);
    $included = Order::factory()->for($account, 'account')->create(['tenant_id' => $tenant->id, 'ordered_at' => '2026-08-10 12:00:00', 'total_amount' => 15000, 'discount_amount' => 1000, 'shipping_amount' => 500, 'currency' => 'TRY']);
    OrderItem::query()->create(['tenant_id' => $tenant->id, 'order_id' => $included->id, 'name' => 'Test ürünü', 'quantity' => 3, 'unit_price_amount' => 5000, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 15000, 'mapping_status' => 'unmapped']);
    Order::factory()->for($account, 'account')->create(['tenant_id' => $tenant->id, 'ordered_at' => '2026-07-01 12:00:00', 'total_amount' => 99000]);
    Order::factory()->for($otherAccount, 'account')->create(['tenant_id' => $other->id, 'ordered_at' => '2026-08-10 12:00:00', 'total_amount' => 88000]);

    $this->actingAs(reportUser($tenant))->get('/reports?from=2026-08-01&to=2026-08-19&currency=TRY')
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Index')
        ->where('summary.sales', 15000)
        ->where('summary.orders', 1)
        ->where('summary.items', 3)
        ->where('summary.average_order', 15000)
        ->where('summary.discounts', 1000)
        ->where('summary.shipping', 500)
        ->has('byChannel', 1)
        ->where('byChannel.0.account', 'Ana mağaza')
        ->where('topProducts.0.name', 'Test ürünü'));
});

it('rejects another tenant account as a report filter', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $account = ChannelAccount::factory()->for($other)->create();

    $this->actingAs(reportUser($tenant))->get('/reports?account='.$account->id)->assertSessionHasErrors('account');
});

it('returns an empty bounded report without special-case failures', function () {
    $tenant = Tenant::factory()->create();

    $this->actingAs(reportUser($tenant))->get('/reports?from=2026-08-01&to=2026-08-03&currency=EUR')
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('summary', ['sales' => 0, 'orders' => 0, 'items' => 0, 'average_order' => 0, 'discounts' => 0, 'shipping' => 0])
        ->has('daily', 3)
        ->where('daily.0.amount', 0)
        ->where('byChannel', [])
        ->where('topProducts', [])
        ->where('syncSummary', ['total' => 0, 'succeeded' => 0, 'failed' => 0, 'running' => 0]));
});

it('applies account currency and inclusive date boundaries to every sales aggregate', function () {
    $tenant = Tenant::factory()->create();
    $channel = Channel::query()->where('code', 'woocommerce')->sole();
    $selected = ChannelAccount::factory()->for($tenant)->create(['channel_id' => $channel->id, 'name' => 'Selected']);
    $other = ChannelAccount::factory()->for($tenant)->create(['channel_id' => $channel->id, 'name' => 'Other']);

    foreach ([
        [$selected, '2026-08-01 00:00:00', 'USD', 1000],
        [$selected, '2026-08-03 23:59:59', 'USD', 2000],
        [$selected, '2026-08-02 12:00:00', 'TRY', 9000],
        [$other, '2026-08-02 12:00:00', 'USD', 8000],
        [$selected, '2026-08-04 00:00:00', 'USD', 7000],
    ] as [$account, $orderedAt, $currency, $amount]) {
        $order = Order::factory()->for($account, 'account')->create(['tenant_id' => $tenant->id, 'ordered_at' => $orderedAt, 'currency' => $currency, 'total_amount' => $amount]);
        OrderItem::query()->create(['tenant_id' => $tenant->id, 'order_id' => $order->id, 'name' => 'Boundary product', 'quantity' => 1, 'unit_price_amount' => $amount, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => $amount]);
    }

    $this->actingAs(reportUser($tenant))->get("/reports?from=2026-08-01&to=2026-08-03&currency=USD&account={$selected->id}")
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('summary.sales', 3000)
        ->where('summary.orders', 2)
        ->where('summary.items', 2)
        ->where('byChannel.0.account', 'Selected')
        ->where('byChannel.0.amount', 3000)
        ->where('topProducts.0.amount', 3000)
        ->where('daily.0.amount', 1000)
        ->where('daily.2.amount', 2000));
});

it('keeps report query count bounded as order volume grows and uses the report index', function () {
    $tenant = Tenant::factory()->create();
    $account = ChannelAccount::factory()->for($tenant)->create();
    $now = now();
    $rows = [];
    for ($index = 0; $index < 300; $index++) {
        $rows[] = [
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'channel_account_id' => $account->id,
            'external_order_id' => "report-load-{$index}", 'status' => 'processing', 'currency' => 'TRY',
            'subtotal_amount' => 1000, 'discount_amount' => 0, 'shipping_amount' => 0, 'tax_amount' => 0, 'total_amount' => 1000,
            'customer_snapshot' => '{}', 'shipping_address_snapshot' => '{}', 'ordered_at' => '2026-08-10 12:00:00', 'imported_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('orders')->insert($chunk);
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });
    $this->actingAs(reportUser($tenant))->get('/reports?from=2026-08-01&to=2026-08-19&currency=TRY')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('summary.orders', 300)->where('summary.sales', 300000));

    $businessQueries = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'orders') || str_contains($sql, 'inventory_items') || str_contains($sql, 'sync_operations'));
    expect($businessQueries->count())->toBeLessThanOrEqual(9)
        ->and($businessQueries->contains(fn (string $sql): bool => str_contains($sql, 'select * from "orders"')))->toBeFalse()
        ->and($businessQueries->contains(fn (string $sql): bool => str_contains($sql, '"order_id" in (')))->toBeFalse();

    DB::statement('SET LOCAL enable_seqscan = off');
    $plan = collect(DB::select('EXPLAIN SELECT COUNT(*) FROM orders WHERE tenant_id = ? AND currency = ? AND ordered_at BETWEEN ? AND ?', [$tenant->id, 'TRY', '2026-08-01', '2026-08-19 23:59:59']))->pluck('QUERY PLAN')->implode(' ');
    expect($plan)->toContain('orders_tenant_currency_ordered_account_index');
});
