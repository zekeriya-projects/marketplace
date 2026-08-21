<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Models\ChannelAccount;

final class SaveTicimaxCredentials
{
    /** @param array{store_url:string,member_code:string} $credentials */
    public function execute(ChannelAccount $account, array $credentials): void
    {
        $credentials['store_url'] = rtrim($credentials['store_url'], '/');
        $account->update(['credentials_encrypted' => $credentials, 'status' => ChannelAccountStatus::Pending, 'last_connected_at' => null]);
    }
}
