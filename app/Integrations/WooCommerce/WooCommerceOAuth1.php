<?php

declare(strict_types=1);

namespace App\Integrations\WooCommerce;

use Psr\Http\Message\RequestInterface;

final class WooCommerceOAuth1
{
    public function sign(
        RequestInterface $request,
        string $consumerKey,
        string $consumerSecret,
        ?string $signatureBaseUrl = null,
    ): RequestInterface {
        $uri = $request->getUri();
        parse_str($uri->getQuery(), $query);

        $oauth = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA256',
            'oauth_timestamp' => (string) time(),
            'oauth_version' => '1.0',
        ];
        $pairs = [];

        foreach ([...$query, ...$oauth] as $key => $value) {
            $pairs[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
        }

        sort($pairs, SORT_STRING);
        $baseUrl = $signatureBaseUrl === null
            ? $uri->getScheme().'://'.$uri->getAuthority().$uri->getPath()
            : rtrim($signatureBaseUrl, '/').$uri->getPath();
        $baseString = strtoupper($request->getMethod())
            .'&'.rawurlencode($baseUrl)
            .'&'.rawurlencode(implode('&', $pairs));
        $oauth['oauth_signature'] = base64_encode(hash_hmac(
            'sha256',
            $baseString,
            rawurlencode($consumerSecret).'&',
            true,
        ));

        return $request->withUri($uri->withQuery(
            http_build_query([...$query, ...$oauth], '', '&', PHP_QUERY_RFC3986),
        ));
    }
}
