<?php

declare(strict_types=1);

namespace App\Integrations\Trendyol\DTO;

use App\Models\ChannelAccount;
use RuntimeException;

final readonly class TrendyolCredentials
{
    public function __construct(public string $sellerId, public string $apiKey, public string $apiSecret, public string $environment) {}

    public static function fromAccount(ChannelAccount $account): self
    {
        $credentials = $account->credentials_encrypted;
        if (! is_array($credentials) || ! isset($credentials['seller_id'], $credentials['api_key'], $credentials['api_secret'], $credentials['environment'])) {
            throw new RuntimeException('Trendyol credentials have not been configured.');
        }

        return new self($credentials['seller_id'], $credentials['api_key'], $credentials['api_secret'], $credentials['environment']);
    }
}
