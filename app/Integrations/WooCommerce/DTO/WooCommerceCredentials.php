<?php

declare(strict_types=1);

namespace App\Integrations\WooCommerce\DTO;

use App\Models\ChannelAccount;
use RuntimeException;

final readonly class WooCommerceCredentials
{
    public function __construct(
        public string $storeUrl,
        public string $consumerKey,
        public string $consumerSecret,
    ) {}

    public static function fromAccount(ChannelAccount $account): self
    {
        $credentials = $account->credentials_encrypted;
        if (! is_array($credentials) || ! isset($credentials['store_url'], $credentials['consumer_key'], $credentials['consumer_secret'])) {
            throw new RuntimeException('WooCommerce credentials have not been configured.');
        }

        return new self($credentials['store_url'], $credentials['consumer_key'], $credentials['consumer_secret']);
    }
}
