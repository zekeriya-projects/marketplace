<?php

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Orders\Actions\DispatchTrendyolOrderPull;
use App\Domain\Orders\Actions\DispatchWooCommerceOrderPull;
use App\Jobs\QueueSmokeJob;
use App\Models\ChannelAccount;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('queue:smoke {token?}', function (?string $token = null): int {
    $token ??= (string) str()->uuid();
    QueueSmokeJob::dispatch($token);
    $this->info("Dispatched queue smoke job: {$token}");
    $this->line("Verify with: php artisan queue:smoke-status {$token}");

    return self::SUCCESS;
})->purpose('Dispatch a Redis-backed job for Horizon verification');

Artisan::command('queue:smoke-status {token}', function (string $token): int {
    $processedAt = Cache::store('redis')->get("queue-smoke:{$token}");

    if ($processedAt === null) {
        $this->error('Queue smoke job has not been processed.');

        return self::FAILURE;
    }

    $this->info("Queue smoke job processed at {$processedAt}");

    return self::SUCCESS;
})->purpose('Check whether Horizon processed a queue smoke job');

Schedule::call(function (DispatchWooCommerceOrderPull $dispatch): void {
    ChannelAccount::query()->where('status', ChannelAccountStatus::Active)
        ->whereHas('channel', fn ($query) => $query->where('code', 'woocommerce'))
        ->each(function (ChannelAccount $account) use ($dispatch): void {
            if (! $account->syncOperations()->where('operation', 'order_pull')->whereIn('status', ['pending', 'running'])->exists()) {
                $dispatch->execute($account);
            }
        });
})->name('woocommerce-order-pull')->everyThirtySeconds()->withoutOverlapping();

Schedule::call(function (DispatchTrendyolOrderPull $dispatch): void {
    ChannelAccount::query()->where('status', ChannelAccountStatus::Active)
        ->whereHas('channel', fn ($query) => $query->where('code', 'trendyol'))
        ->each(function (ChannelAccount $account) use ($dispatch): void {
            if (! $account->syncOperations()->where('operation', 'order_pull')->whereIn('status', ['pending', 'running'])->exists()) {
                $dispatch->execute($account);
            }
        });
})->name('trendyol-order-pull')->everyThirtySeconds()->withoutOverlapping();

Schedule::command('sync:archive')->name('sync-operation-archive')->dailyAt('02:30')->withoutOverlapping()
    ->onFailure(fn () => Log::error('Scheduled synchronization-operation archival failed.'));
