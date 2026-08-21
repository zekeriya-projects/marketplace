<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Enums\ChannelAccountStatus;
use App\Models\ChannelAccount;

final class SaveWooCommerceCredentials
{
    /** @param array{store_url: string, consumer_key: string, consumer_secret: string} $credentials */
    public function execute(ChannelAccount $account, array $credentials): void
    {
        $shippedStatus = filled($credentials['shipped_status'] ?? null) ? (string) $credentials['shipped_status'] : null;
        unset($credentials['shipped_status']);
        $credentials['store_url'] = rtrim($credentials['store_url'], '/');
        $settings = $account->settings ?? [];
        $mappings = is_array($settings['order_status_mappings'] ?? null) ? $settings['order_status_mappings'] : [];
        if ($shippedStatus === null) {
            unset($mappings['shipped']);
        } else {
            $mappings['shipped'] = $shippedStatus;
        }
        $account->update([
            'credentials_encrypted' => $credentials,
            'settings' => [...$settings, 'order_status_mappings' => $mappings],
            'status' => ChannelAccountStatus::Pending,
            'last_connected_at' => null,
        ]);
    }
}
