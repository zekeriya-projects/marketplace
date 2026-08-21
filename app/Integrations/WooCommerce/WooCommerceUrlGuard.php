<?php

declare(strict_types=1);

namespace App\Integrations\WooCommerce;

use InvalidArgumentException;

class WooCommerceUrlGuard
{
    public function assertSafe(string $url): void
    {
        $parts = $this->assertConfigurable($url);
        $host = strtolower($parts['host']);
        if ($this->isAllowedLocalUrl(strtolower($parts['scheme']), $host)) {
            return;
        }

        $addresses = $this->resolve($host);
        if ($addresses === [] || collect($addresses)->contains(fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) {
            throw new InvalidArgumentException('The WooCommerce store URL must resolve only to public network addresses.');
        }
    }

    /** @return array{scheme: string, host: string, port?: int, path?: string} */
    public function assertConfigurable(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The WooCommerce store URL must be a public HTTPS URL without credentials, query parameters, or fragments.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($this->isAllowedLocalUrl($scheme, $host)) {
            return $parts;
        }

        if ($scheme !== 'https') {
            throw new InvalidArgumentException('The WooCommerce store URL must be a public HTTPS URL without credentials, query parameters, or fragments.');
        }

        if ($host === 'localhost' || (filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) {
            throw new InvalidArgumentException('The WooCommerce store URL cannot point to a local or private network address.');
        }

        return $parts;
    }

    private function isAllowedLocalUrl(string $scheme, string $host): bool
    {
        return app()->environment('local')
            && config('integrations.woocommerce.allow_local_urls') === true
            && in_array($scheme, ['http', 'https'], true)
            && in_array($host, config('integrations.woocommerce.local_hosts', []), true);
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        return collect($records ?: [])->map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null)->filter()->values()->all();
    }
}
