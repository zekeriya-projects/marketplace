<?php

declare(strict_types=1);

namespace App\Integrations\Hepsiburada\DTO;

use App\Models\ChannelAccount;
use RuntimeException;

final readonly class HepsiburadaCredentials
{
    public function __construct(public string $merchantId, public string $username, public string $password, public string $environment) {}

    public static function fromAccount(ChannelAccount $account): self
    {
        $credentials = $account->credentials_encrypted;
        if (! is_array($credentials) || ! isset($credentials['merchant_id'], $credentials['username'], $credentials['password'], $credentials['environment'])) {
            throw new RuntimeException('Hepsiburada credentials have not been configured.');
        }

        return new self($credentials['merchant_id'], $credentials['username'], $credentials['password'], $credentials['environment']);
    }
}
