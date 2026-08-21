<?php

return [
    'operation_retention_days' => [
        'succeeded' => (int) env('SYNC_SUCCEEDED_RETENTION_DAYS', 30),
        'skipped' => (int) env('SYNC_SKIPPED_RETENTION_DAYS', 30),
        'failed' => (int) env('SYNC_FAILED_RETENTION_DAYS', 180),
    ],
    'cleanup_chunk_size' => (int) env('SYNC_CLEANUP_CHUNK_SIZE', 1000),
];
