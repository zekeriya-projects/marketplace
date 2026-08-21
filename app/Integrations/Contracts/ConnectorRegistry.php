<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

interface ConnectorRegistry
{
    public function for(string $channelCode): ChannelConnector;
}
