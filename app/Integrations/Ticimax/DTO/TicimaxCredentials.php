<?php

declare(strict_types=1);

namespace App\Integrations\Ticimax\DTO;

use App\Models\ChannelAccount;
use RuntimeException;

final readonly class TicimaxCredentials
{
    public function __construct(public string $storeUrl, public string $memberCode) {}

    public static function fromAccount(ChannelAccount $account): self
    {
        $credentials = $account->credentials_encrypted;
        if (! is_array($credentials) || ! is_string($credentials['store_url'] ?? null) || ! is_string($credentials['member_code'] ?? null)) {
            throw new RuntimeException('Ticimax credentials are missing.');
        }

        return new self(rtrim($credentials['store_url'], '/'), $credentials['member_code']);
    }

    public function productWsdl(): string
    {
        return $this->storeUrl.'/Servis/UrunServis.svc?wsdl';
    }

    public function orderWsdl(): string
    {
        return $this->storeUrl.'/Servis/SiparisServis.svc?wsdl';
    }
}
