<?php

declare(strict_types=1);

namespace App\Integrations\Ticimax\Contracts;

interface TicimaxSoapTransport
{
    /** @param array<string, mixed> $arguments */
    public function call(string $wsdl, string $method, array $arguments): mixed;
}
