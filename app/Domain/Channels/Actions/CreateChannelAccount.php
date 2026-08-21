<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Models\ChannelAccount;
use App\Models\Tenant;

final class CreateChannelAccount
{
    /** @param array{channel_id: string, name: string} $attributes */
    public function execute(Tenant $tenant, array $attributes): ChannelAccount
    {
        return $tenant->channelAccounts()->create($attributes);
    }
}
