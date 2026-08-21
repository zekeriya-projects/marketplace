<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantRole;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\SyncOperation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function archivalAccount(Tenant $tenant): ChannelAccount
{
    return ChannelAccount::factory()->for($tenant)->create([
        'channel_id' => Channel::query()->where('code', 'woocommerce')->firstOrFail()->id,
    ]);
}

function archivalOperation(ChannelAccount $account, string $status, Carbon $at): SyncOperation
{
    $operation = SyncOperation::query()->create([
        'tenant_id' => $account->tenant_id,
        'channel_account_id' => $account->id,
        'operation' => 'inventory_push',
        'status' => $status,
        'created_at' => $at,
        'finished_at' => $at,
        'safe_error_message' => $status === 'failed' ? 'Güvenli hata' : null,
    ]);
    $operation->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

    return $operation;
}

function archivalUser(Tenant $tenant): User
{
    $user = User::factory()->create(['active_tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id, ['role' => TenantRole::Owner->value]);

    return $user;
}

it('archives expired terminal operations in restartable chunks while preserving active and boundary rows', function () {
    $this->travelTo(Carbon::parse('2026-08-20 12:00:00'));
    config()->set('sync.operation_retention_days', ['succeeded' => 30, 'skipped' => 30, 'failed' => 180]);
    $account = archivalAccount(Tenant::factory()->create());

    archivalOperation($account, 'succeeded', now()->subDays(31));
    archivalOperation($account, 'succeeded', now()->subDays(31)->addSeconds(5));
    $boundary = archivalOperation($account, 'succeeded', now()->subDays(30));
    $recentFailure = archivalOperation($account, 'failed', now()->subDays(179));
    $pending = archivalOperation($account, 'pending', now()->subYear());
    $running = archivalOperation($account, 'running', now()->subYear());

    $this->artisan('sync:archive', ['--chunk' => 1])->expectsOutput('Archived 2 synchronization operations.')->assertSuccessful();
    $this->artisan('sync:archive', ['--chunk' => 1])->expectsOutput('Archived 0 synchronization operations.')->assertSuccessful();

    expect(SyncOperation::query()->pluck('id')->all())->toContain($boundary->id, $recentFailure->id, $pending->id, $running->id)
        ->and((int) DB::table('sync_operation_archives')->sum('operation_count'))->toBe(2)
        ->and(DB::table('sync_operation_archives')->count())->toBe(1);
});

it('uses status-specific retention and keeps archived totals visible in grouped tenant history', function () {
    $this->travelTo(Carbon::parse('2026-08-20 12:00:00'));
    config()->set('sync.operation_retention_days', ['succeeded' => 30, 'skipped' => 10, 'failed' => 180]);
    $tenant = Tenant::factory()->create();
    $account = archivalAccount($tenant);
    archivalOperation($account, 'succeeded', now()->subDays(31));
    archivalOperation($account, 'skipped', now()->subDays(11));
    archivalOperation($account, 'failed', now()->subDays(181));

    $this->artisan('sync:archive')->assertSuccessful();
    $this->actingAs(archivalUser($tenant))->get('/sync?period=all')->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.events', 3)->where('summary.succeeded', 1)->where('summary.failed', 1)->has('groups.data', 3));
    $this->actingAs(archivalUser($tenant))->get('/reports?from=2026-02-01&to=2026-08-20&currency=TRY&account='.$account->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('syncSummary.total', 3)->where('syncSummary.succeeded', 1)->where('syncSummary.failed', 1));
});

it('rejects unsafe cleanup configuration and registers the daily scheduler', function () {
    config()->set('sync.operation_retention_days.succeeded', 0);

    expect(fn () => Artisan::call('sync:archive'))->toThrow(RuntimeException::class, 'Invalid retention period');

    $this->artisan('schedule:list')->expectsOutputToContain('sync:archive')->assertSuccessful();
});
