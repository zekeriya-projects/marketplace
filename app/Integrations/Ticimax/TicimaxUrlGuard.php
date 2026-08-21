<?php

declare(strict_types=1);

namespace App\Integrations\Ticimax;

use InvalidArgumentException;

class TicimaxUrlGuard
{
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Ticimax mağaza adresi, kimlik bilgisi veya parametre içermeyen bir HTTPS adresi olmalıdır.');
        }
        $host = strtolower($parts['host']);
        if ($host === 'localhost' || (filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) {
            throw new InvalidArgumentException('Ticimax mağaza adresi yerel veya özel bir ağa yönlenemez.');
        }
        $addresses = $this->resolve($host);
        if ($addresses === [] || collect($addresses)->contains(fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) {
            throw new InvalidArgumentException('Ticimax mağaza adresi yalnızca genel ağ adreslerine çözülmelidir.');
        }
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return collect(dns_get_record($host, DNS_A | DNS_AAAA) ?: [])->map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null)->filter()->values()->all();
    }
}
