<?php

declare(strict_types=1);

namespace App\Integrations\Ticimax;

use App\Integrations\Ticimax\Contracts\TicimaxSoapTransport;
use SoapClient;

final class NativeTicimaxSoapTransport implements TicimaxSoapTransport
{
    public function call(string $wsdl, string $method, array $arguments): mixed
    {
        $client = new SoapClient($wsdl, [
            'cache_wsdl' => WSDL_CACHE_NONE,
            'connection_timeout' => 10,
            'exceptions' => true,
            'keep_alive' => false,
            'soap_version' => SOAP_1_1,
            'stream_context' => stream_context_create(['http' => ['timeout' => 30], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]),
        ]);

        return $client->__soapCall($method, [$arguments]);
    }
}
