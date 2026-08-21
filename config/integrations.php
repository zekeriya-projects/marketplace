<?php

declare(strict_types=1);

return [
    'woocommerce' => [
        'allow_local_urls' => (bool) env('WOOCOMMERCE_ALLOW_LOCAL_URLS', false),
        'local_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('WOOCOMMERCE_LOCAL_HOSTS', 'host.docker.internal,woocommerce,localhost,127.0.0.1')),
        ))),
        'local_host_bridge' => env('WOOCOMMERCE_LOCAL_HOST_BRIDGE', 'host.docker.internal'),
    ],
];
