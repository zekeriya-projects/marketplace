<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class QueueSmokeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $token) {}

    public function handle(): void
    {
        Cache::store('redis')->put("queue-smoke:{$this->token}", now()->toIso8601String(), now()->addMinutes(10));
    }
}
