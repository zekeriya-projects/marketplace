<?php

declare(strict_types=1);

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Jobs\CheckTrendyolListingBatchJob;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

it('rate limits repeated login attempts by identity and address', function () {
    $payload = ['email' => 'missing@example.com', 'password' => 'wrong-password'];
    foreach (range(1, 5) as $_) {
        $this->post('/login', $payload)->assertSessionHasErrors('email');
    }

    $this->post('/login', $payload)->assertTooManyRequests();
});

it('marks an exhausted Trendyol listing batch check as safely failed', function () {
    $tenant = Tenant::factory()->create();
    $account = trendyolAccount($tenant, ['status' => ChannelAccountStatus::Active]);
    $operation = $account->syncOperations()->create(['tenant_id' => $tenant->id, 'operation' => 'listing_publish', 'status' => SyncOperationStatus::Running]);

    (new CheckTrendyolListingBatchJob($operation->id))->failed(new RuntimeException('raw provider secret'));

    expect($operation->fresh()->status)->toBe(SyncOperationStatus::Failed)
        ->and($operation->fresh()->safe_error_message)->not->toContain('raw provider secret');
});

it('writes structured safe context when synchronization status changes', function () {
    Log::spy();
    $tenant = Tenant::factory()->create();
    $account = trendyolAccount($tenant);
    $operation = $account->syncOperations()->create(['tenant_id' => $tenant->id, 'operation' => 'connection_test']);

    $operation->update(['status' => SyncOperationStatus::Running, 'attempt' => 1]);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'sync_operation_status_changed'
        && $context['tenant_id'] === $tenant->id
        && $context['channel_account_id'] === $account->id
        && $context['status'] === 'running'
        && ! array_key_exists('credentials', $context))->once();
});
