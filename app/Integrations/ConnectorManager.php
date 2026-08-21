<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Integrations\Contracts\ChannelConnector;
use App\Integrations\Contracts\ConnectorRegistry;
use LogicException;

final class ConnectorManager implements ConnectorRegistry
{
    /** @var array<string, ChannelConnector> */
    private array $connectors = [];

    public function register(string $channelCode, ChannelConnector $connector): void
    {
        $this->connectors[$channelCode] = $connector;
    }

    public function for(string $channelCode): ChannelConnector
    {
        return $this->connectors[$channelCode] ?? throw new LogicException("No connector is registered for [{$channelCode}].");
    }
}
