<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Models\ChannelAccount;

final class SaveHepsiburadaCredentials
{
    /** @param array{merchant_id: string, username: string, password: string, environment: string} $credentials */
    public function execute(ChannelAccount $account, array $credentials): void
    {
        $account->update(['credentials_encrypted' => $credentials, 'status' => ChannelAccountStatus::Pending, 'last_connected_at' => null]);
    }
}
