<?php

declare(strict_types=1);

return [
    'waits' => [
        'redis:default' => 60,
        'redis:imports' => 120,
        'redis:orders' => 120,
    ],

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default', 'imports', 'orders', 'inventory-sync', 'price-sync'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 210,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],
        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
        ],
        'testing' => [
            'supervisor-1' => [
                'maxProcesses' => 1,
            ],
        ],
    ],
];
