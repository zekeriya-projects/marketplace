<?php

declare(strict_types=1);

use App\Jobs\QueueSmokeJob;
use Illuminate\Support\Facades\Queue;

it('dispatches the queue smoke job', function () {
    Queue::fake();

    $this->artisan('queue:smoke', ['token' => 'pest-smoke'])->assertSuccessful();

    Queue::assertPushed(
        QueueSmokeJob::class,
        fn (QueueSmokeJob $job) => $job->token === 'pest-smoke',
    );
});
